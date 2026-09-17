<?php

namespace App\Services\Performance;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientSatisfactionRating;
use App\Models\KpiWeightConfig;
use App\Models\Payment;
use App\Models\PerformanceSetting;
use App\Models\SalesTarget;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Services\TaskInvolvementService;
use Carbon\Carbon;

class PerformanceCalculationService
{
    private const ACTIVE_STATUSES = ['Pending', 'In Progress', 'On Hold'];

    // Request-lifetime memo of the singletons that are otherwise re-queried once
    // per employee — the scoreboard and snapshot command reuse one service
    // instance across the whole user loop, so this collapses N lookups into 1.
    private ?PerformanceSetting $settingsCache = null;
    private ?\Illuminate\Support\Collection $weightConfigsCache = null;

    private function settings(): PerformanceSetting
    {
        return $this->settingsCache ??= PerformanceSetting::current();
    }

    private function weightConfigs(): \Illuminate\Support\Collection
    {
        return $this->weightConfigsCache ??= KpiWeightConfig::all();
    }

    // ── Cohort prefetch ──────────────────────────────────────────────────
    //
    // Scoring one employee costs six queries. The scoreboard scores everyone,
    // so the cost was six per head — 66 queries for ten people, and it grows
    // with the payroll. prefetch() loads the same rows for the whole cohort in
    // a fixed five, and the per-employee methods read from it.
    //
    // Nothing here changes what is computed: each method still derives its
    // numbers from exactly the rows it would have fetched for itself, and falls
    // back to querying when the cohort was not prefetched (a single-employee
    // page, or any other caller).

    private ?string $prefetchPeriod = null;
    /** @var array<int,\Illuminate\Support\Collection> */
    private array $tasksByUser = [];
    /** @var array<int,SalesTarget> */
    private array $targetsByUser = [];
    /** @var array<int,float> */
    private array $salesByUser = [];
    /** @var array<int,\Illuminate\Support\Collection> */
    private array $ratingsByUser = [];

    /**
     * @param  \Illuminate\Support\Collection<int,User>|array<int,User>  $users
     */
    public function prefetch($users, string $period): void
    {
        $ids = collect($users)->pluck('id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        [$start, $end] = $this->periodBounds($period);

        // One task set per employee, serving task completion, on-time and
        // revision rate — all three are subsets of "their tasks due this period".
        $this->tasksByUser = $this->loadTasks($ids, $period);

        $this->targetsByUser = SalesTarget::whereIn('user_id', $ids)
            ->where('period', $period)
            ->get()
            ->keyBy('user_id')
            ->all();

        // Sum of paid revenue per account manager. whereNull('deleted_at')
        // reproduces the soft-delete scope that whereHas('client') applied.
        $this->salesByUser = Payment::query()
            ->join('clients', 'clients.id', '=', 'payments.client_id')
            ->where('payments.status', 'Paid')
            ->whereBetween('payments.payment_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('clients.assigned_to', $ids)
            ->whereNull('clients.deleted_at')
            ->groupBy('clients.assigned_to')
            ->selectRaw('clients.assigned_to as user_id, sum(payments.amount) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($total) => (float) $total)
            ->all();

        $this->ratingsByUser = ClientSatisfactionRating::included()
            ->whereIn('employee_id', $ids)
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->groupBy('employee_id')
            ->all();

        $this->clientCareByUser = $this->loadClientCare($ids, $period);

        $this->prefetchPeriod = $period;
    }

    /** @var array<int,array<string,mixed>> */
    private array $clientCareByUser = [];

    /** True when this exact period was prefetched for the cohort. */
    private function prefetched(string $period): bool
    {
        return $this->prefetchPeriod === $period;
    }

    /**
     * The employee's tasks due in the period — from the cohort load when there
     * is one, otherwise fetched for them alone.
     */
    private function tasksFor(User $user, string $period): \Illuminate\Support\Collection
    {
        if ($this->prefetched($period)) {
            return $this->tasksByUser[$user->id] ?? collect();
        }

        return $this->loadTasks(collect([$user->id]), $period)[$user->id] ?? collect();
    }

    // ── Task credit ──────────────────────────────────────────────────────
    //
    // A task counts for a person in proportion to the work they did on it, as
    // recorded by TaskInvolvementService: whoever holds it now and anyone who
    // contributed share it by their work points. Someone it merely passed
    // through, whoever created it, and whoever reviewed it get no share, so it
    // neither helps nor hurts their task KPIs.
    //
    // Every task-based KPI is then a share-weighted rate:
    //
    //     completion %  = Σ share(completed)            ÷ Σ share(counted tasks)   × 100
    //     on-time %     = Σ share(completed on time)    ÷ Σ share(completed)       × 100
    //     revision KPI  = Σ share(sent back for a mistake) ÷ Σ share(submitted)   × 100
    //
    // A task done alone has share 1, so for the ordinary case every number is
    // exactly what it was before involvement existed. The counts shown next to
    // the rates stay whole tasks; the `credited_*` figures are the weighted sums
    // the rates were computed from, so a scorecard can always be re-derived by
    // hand from the per-task breakdown in taskCredit().

    /**
     * Tasks due in the period that count for each of these users, each a copy
     * carrying that user's `work_share`.
     *
     * @param  \Illuminate\Support\Collection<int,int>  $ids
     * @return array<int,\Illuminate\Support\Collection<int,Task>>
     */
    private function loadTasks(\Illuminate\Support\Collection $ids, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        $tasks = Task::query()
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($q) => $q
                ->whereIn('assigned_to', $ids)
                ->orWhereHas('involvements', fn ($inv) => $inv->whereIn('user_id', $ids)->where('points', '>', 0)))
            ->withCount('revisions')
            ->with([
                'revisions' => fn ($q) => $q->where('reason_category', 'Employee Mistake'),
                'involvements',
            ])
            ->orderBy('id')
            ->get();

        $byUser = [];
        foreach ($tasks as $task) {
            foreach (self::workSharesOf($task) as $userId => $share) {
                if ($share > 0 && $ids->contains($userId)) {
                    $byUser[$userId][] = (clone $task)->setAttribute('work_share', $share);
                }
            }
        }

        return array_map(fn (array $list) => collect($list), $byUser);
    }

    /**
     * Each doer's share of one task (loaded with its involvements).
     *
     * The task's current assignee is treated as the holder whatever its rows
     * last recorded, so credit follows the task even if something reassigned it
     * without going through TaskService. A task with no involvement recorded at
     * all counts wholly for its assignee, as every task did before.
     *
     * @return array<int,float>
     */
    public static function workSharesOf(Task $task): array
    {
        $holder = $task->assigned_to ? (int) $task->assigned_to : null;

        if ($task->involvements->isEmpty()) {
            return $holder ? [$holder => 1.0] : [];
        }

        $rows = $task->involvements->map(fn ($inv) => [
            'user_id' => (int) $inv->user_id,
            'points'  => (float) $inv->points,
            'role'    => match (true) {
                (int) $inv->user_id === $holder => TaskInvolvementService::ROLE_PRIMARY,
                (float) $inv->points > 0        => TaskInvolvementService::ROLE_CONTRIBUTOR,
                default                         => $inv->role === TaskInvolvementService::ROLE_PRIMARY
                                                      ? TaskInvolvementService::ROLE_PASSED_THROUGH
                                                      : $inv->role,
            },
        ])->all();

        if ($holder && !$task->involvements->contains(fn ($inv) => (int) $inv->user_id === $holder)) {
            $rows[] = ['user_id' => $holder, 'points' => 0.0, 'role' => TaskInvolvementService::ROLE_PRIMARY];
        }

        return TaskInvolvementService::workShares($rows);
    }

    /**
     * The per-task audit trail behind a user's task KPIs for the period: every
     * task they were involved in, with their role, points, the events that
     * earned them, and the share that was credited.
     *
     * Includes tasks that earned no share (passed through, reviewed, created)
     * so a scorecard can show why they did not count.
     *
     * @return array<int,array<string,mixed>>
     */
    public function taskCredit(User $user, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        $tasks = Task::query()
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($q) => $q
                ->where('assigned_to', $user->id)
                ->orWhereHas('involvements', fn ($inv) => $inv->where('user_id', $user->id)))
            ->with('involvements')
            ->orderBy('due_date')->orderBy('id')
            ->get(['id', 'title', 'status', 'assigned_to', 'created_by', 'due_date', 'due_at']);

        return $tasks->map(function (Task $task) use ($user) {
            $mine  = $task->involvements->first(fn ($inv) => (int) $inv->user_id === (int) $user->id);
            $share = self::workSharesOf($task)[$user->id] ?? 0.0;

            return [
                'task_id'   => $task->id,
                'title'     => $task->title,
                'status'    => $task->status,
                'due'       => $task->due_date?->toDateString(),
                'role'      => (int) $task->assigned_to === (int) $user->id
                                   ? TaskInvolvementService::ROLE_PRIMARY
                                   : ($mine?->role ?? TaskInvolvementService::ROLE_OTHER),
                'points'    => (float) ($mine?->points ?? 0),
                'review_points' => (float) ($mine?->review_points ?? 0),
                'breakdown' => $mine?->breakdown ?? [],
                'share'     => $share,
                'counted'   => $share > 0,
                'tracked'   => $task->involvements->isNotEmpty(),
            ];
        })->all();
    }

    public function periodBounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        return [$start->copy()->startOfDay(), $start->copy()->endOfMonth()->endOfDay()];
    }

    public function previousPeriod(string $period): string
    {
        return Carbon::createFromFormat('Y-m', $period)->subMonth()->format('Y-m');
    }

    public function taskCompletion(User $user, string $period): array
    {
        $settings = $this->settings();

        $tasks = $this->tasksFor($user, $period);

        $total     = $tasks->count();
        $completed = $tasks->where('status', 'Completed')->count();
        $cancelled = $tasks->where('status', 'Cancelled')->count();
        $pending   = $tasks->where('status', 'Pending')->count();
        $onHold    = $tasks->where('status', 'On Hold')->count();
        $inProgress = $tasks->where('status', 'In Progress')->count();
        // is_overdue honours Task::$settledStatuses, so submitted work waiting on
        // a reviewer is not counted late against the person who handed it in,
        // and an exact deadline is late from that moment, a date-only one from
        // the next day.
        $overdue   = $tasks->filter(fn (Task $t) => $t->is_overdue)->count();

        $counted = $settings->count_cancelled_against_kpi ? $tasks : $tasks->where('status', '!=', 'Cancelled');
        $creditedTotal     = self::credit($counted);
        $creditedCompleted = self::credit($counted->where('status', 'Completed'));
        $completionPct = $counted->isNotEmpty() && $creditedTotal > 0 ? round($creditedCompleted / $creditedTotal * 100, 2) : null;

        return [
            'total' => $total, 'completed' => $completed, 'pending' => $pending,
            'in_progress' => $inProgress, 'on_hold' => $onHold, 'overdue' => $overdue, 'cancelled' => $cancelled,
            'completion_pct' => $completionPct,
            'shared' => $tasks->filter(fn (Task $t) => self::shareOf($t) < 1)->count(),
            'credited_total' => round($creditedTotal, 4),
            'credited_completed' => round($creditedCompleted, 4),
        ];
    }

    /** This user's share of a task loaded by loadTasks(). */
    private static function shareOf(Task $task): float
    {
        return (float) ($task->getAttribute('work_share') ?? 1.0);
    }

    /** Sum of shares — how many whole tasks' worth of credit a set represents. */
    private static function credit(iterable $tasks): float
    {
        $sum = 0.0;
        foreach ($tasks as $task) {
            $sum += self::shareOf($task);
        }

        return $sum;
    }

    public function onTimeCompletion(User $user, string $period): array
    {
        $completed = $this->tasksFor($user, $period)
            ->where('status', 'Completed')
            ->filter(fn (Task $t) => $t->completion_date !== null)
            ->values();

        $before = $onTime = $after = 0;
        $creditedOnTime = $creditedLate = $weightedDelay = 0.0;

        foreach ($completed as $task) {
            $share = self::shareOf($task);
            $delay = self::delayDays($task);

            if ($delay > 0) {
                $after++;
                $creditedLate  += $share;
                $weightedDelay += $delay * $share;
            } else {
                $task->completion_date->lt($task->due_date->copy()->startOfDay()) ? $before++ : $onTime++;
                $creditedOnTime += $share;
            }
        }

        $totalCompleted = $completed->count();
        $creditedCompleted = $creditedOnTime + $creditedLate;
        $onTimeRate = $totalCompleted > 0 && $creditedCompleted > 0 ? round($creditedOnTime / $creditedCompleted * 100, 2) : null;
        $avgDelay = $after > 0 && $creditedLate > 0 ? round($weightedDelay / $creditedLate, 2) : null;

        return [
            'total_completed' => $totalCompleted,
            'before_deadline' => $before, 'on_deadline' => $onTime, 'after_deadline' => $after,
            'avg_delay_days' => $avgDelay, 'on_time_rate' => $onTimeRate,
            'credited_completed' => round($creditedCompleted, 4),
            'credited_on_time' => round($creditedOnTime, 4),
        ];
    }

    /**
     * How late a completed task was, in days; 0 when it met its deadline.
     *
     * An exact deadline is met or missed at that moment, and lateness is the
     * fraction of days past it. A date-only deadline is met by finishing any
     * time that day, as it always was.
     *
     * Whole days are cast to int deliberately: Carbon 3 returns floats from
     * diffInDays(), so the old `=== 0` test never matched and every task
     * finished on its due date was counted late with a zero delay.
     */
    private static function delayDays(Task $task): float
    {
        if ($task->dueHasTime() && $task->completed_at) {
            $seconds = $task->due_at->diffInSeconds($task->completed_at, false);

            return $seconds > 0 ? round($seconds / 86400, 2) : 0.0;
        }

        return (float) max(0, (int) $task->due_date->copy()->startOfDay()->diffInDays($task->completion_date->copy()->startOfDay(), false));
    }

    public function deadlineExtensionHistory(Task $task): array
    {
        $events = [];

        foreach (TaskActivity::where('task_id', $task->id)->where('action', 'Updated')->get() as $activity) {
            $old = json_decode((string) $activity->old_value, true);
            $new = json_decode((string) $activity->new_value, true);

            if (is_array($old) && is_array($new) && ($old['due_date'] ?? null) !== ($new['due_date'] ?? null)) {
                $events[] = [
                    'changed_at'   => $activity->created_at,
                    'changed_by'   => $activity->user_id,
                    'previous_due' => $old['due_date'] ?? null,
                    'new_due'      => $new['due_date'] ?? null,
                ];
            }
        }

        return $events;
    }

    public function revisionRate(User $user, string $period): array
    {
        // Same filter the query applied: completed work, plus anything that was
        // sent back regardless of where it ended up.
        $tasks = $this->tasksFor($user, $period)
            ->filter(fn (Task $t) => $t->status === 'Completed' || $t->revisions_count > 0)
            ->values();

        $totalSubmitted = $tasks->count();
        $requiringRevision = $tasks->where('revisions_count', '>', 0)->count();
        $employeeMistakeCount = $tasks->filter(fn (Task $t) => $t->revisions->isNotEmpty())->count();
        $totalRevisionRequests = (int) $tasks->sum('revisions_count');

        // Rates are share-weighted: a mistake on a task someone did a third of
        // weighs a third as much in their KPI.
        $creditedSubmitted = self::credit($tasks);
        $creditedRevised   = self::credit($tasks->where('revisions_count', '>', 0));
        $creditedMistakes  = self::credit($tasks->filter(fn (Task $t) => $t->revisions->isNotEmpty()));
        $rate = fn (float $part) => $totalSubmitted > 0 && $creditedSubmitted > 0 ? round($part / $creditedSubmitted * 100, 2) : null;

        return [
            'total_submitted' => $totalSubmitted,
            'approved_first_submission' => $totalSubmitted - $requiringRevision,
            'requiring_revision' => $requiringRevision,
            'total_revision_requests' => $totalRevisionRequests,
            'avg_revisions_per_task' => $totalSubmitted > 0 ? round($totalRevisionRequests / $totalSubmitted, 2) : null,
            'revision_rate_all' => $rate($creditedRevised),
            'revision_rate_kpi' => $rate($creditedMistakes),
            'credited_submitted' => round($creditedSubmitted, 4),
            'credited_mistakes' => round($creditedMistakes, 4),
        ];
    }

    public function salesAchievement(User $user, string $period): ?array
    {
        $prefetched = $this->prefetched($period);

        $target = $prefetched
            ? ($this->targetsByUser[$user->id] ?? null)
            : SalesTarget::where('user_id', $user->id)->where('period', $period)->first();

        if (!$target) {
            return null;
        }

        [$start, $end] = $this->periodBounds($period);

        $achieved = $prefetched
            ? ($this->salesByUser[$user->id] ?? 0.0)
            : (float) Payment::where('status', 'Paid')
                ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
                ->whereHas('client', fn ($q) => $q->where('assigned_to', $user->id))
                ->sum('amount');

        $targetAmount = (float) $target->target_amount;
        $pct = $targetAmount > 0 ? round($achieved / $targetAmount * 100, 2) : null;
        $remaining = max($targetAmount - $achieved, 0);

        $now = now();
        $daysRemaining = $now->lessThan($end) ? $now->diffInDays($end) + 1 : 0;
        $dailyRequired = $daysRemaining > 0 ? round($remaining / $daysRemaining, 2) : null;

        return [
            'target_amount' => $targetAmount, 'achieved' => $achieved, 'pct' => $pct,
            'remaining' => $remaining, 'days_remaining' => $daysRemaining, 'daily_required' => $dailyRequired,
        ];
    }

    public function clientSatisfaction(User $user, string $period): ?array
    {
        [$start, $end] = $this->periodBounds($period);

        $ratings = $this->prefetched($period)
            ? ($this->ratingsByUser[$user->id] ?? collect())
            : $user->satisfactionRatings()->included()
                ->whereBetween('created_at', [$start, $end])
                ->get();

        if ($ratings->isEmpty()) {
            return null;
        }

        $avg = $ratings->avg('rating');

        return [
            'count' => $ratings->count(),
            'avg_rating' => round($avg, 2),
            'score' => round($avg * 20, 2),
            'positive' => $ratings->where('rating', '>=', 4)->count(),
            'complaints' => $ratings->where('rating', '<=', 2)->count(),
        ];
    }

    // ── Client care ──────────────────────────────────────────────────────
    //
    // Credit for bringing clients in and looking after the clients you are
    // responsible for — measured from what was actually done to them, never
    // from opening a client page.
    //
    //   Your clients     assigned to you, or added by you and assigned to no one.
    //                    Active ones are those Running or Warning.
    //   Clients added    added by you in the month, by hand (an import is data
    //                    entry, not bringing a client in), and not since deleted.
    //   Upkeep           a day on which you did real work on one of your clients:
    //                    edited it or changed its status, added a note, a product
    //                    update, a project update or a document, scheduled or
    //                    completed a meeting, added or edited a brand, replied to
    //                    its support ticket, or opened its portal account.
    //
    //   points      = 3 × clients added  +  upkeep days
    //                 (upkeep capped at 4 days per client per month — ten edits
    //                 to one client in a day are one day, and one client can't
    //                 carry the month)
    //   activity %  = points ÷ monthly target (Settings) × 100, at most 100
    //   coverage %  = active clients of yours with upkeep this month
    //                 ÷ all your active clients × 100
    //   score       = average of coverage % and activity %,
    //                 or activity % alone when you have no active clients
    //
    // Nobody with no clients, no clients added and no upkeep gets this KPI at
    // all, so it never pulls down someone whose job isn't client work.

    public const CLIENT_ADDED_POINTS = 3;
    public const UPKEEP_DAYS_CAP_PER_CLIENT = 4;
    public const ACTIVE_CLIENT_STATUSES = ['Running', 'Warning'];

    /** Activity-log entries that count as looking after a client. */
    public const CLIENT_UPKEEP_ACTIONS = [
        'Client'                => ['Updated', 'Status Changed'],
        'Note'                  => ['Created'],
        'Product'               => ['Update Created'],
        'Project Update'        => ['Posted'],
        'Document'              => ['Uploaded'],
        'Meeting'               => ['Scheduled', 'Completed'],
        'Brand'                 => ['Created', 'Updated'],
        'Support Ticket'        => ['Replied'],
        'Client Portal Account' => ['Created'],
    ];

    public function clientCare(User $user, string $period): ?array
    {
        return $this->prefetched($period)
            ? ($this->clientCareByUser[$user->id] ?? null)
            : ($this->loadClientCare(collect([$user->id]), $period)[$user->id] ?? null);
    }

    /**
     * The client-care picture for each of these users, in a fixed handful of
     * queries whatever the headcount.
     *
     * @param  \Illuminate\Support\Collection<int,int>  $ids
     * @return array<int,array<string,mixed>|null>
     */
    private function loadClientCare(\Illuminate\Support\Collection $ids, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);
        $target = max(1, (int) ($this->settings()->client_care_target_points ?? 20));

        // Whose clients: assigned to them, or added by them and assigned to no one.
        $portfolio = Client::query()
            ->where(fn ($q) => $q
                ->whereIn('assigned_to', $ids)
                ->orWhere(fn ($q) => $q->whereNull('assigned_to')->whereIn('created_by', $ids)))
            ->get(['id', 'assigned_to', 'created_by', 'client_status']);

        $owner = fn ($client) => (int) ($client->assigned_to ?? $client->created_by);
        $portfolioByUser = $portfolio->groupBy($owner);

        // Added by hand this month.
        $added = Client::query()
            ->whereIn('created_by', $ids)
            ->whereBetween('created_at', [$start, $end])
            ->get(['id', 'created_by']);
        $imported = $added->isEmpty() ? collect() : ActivityLog::query()
            ->where('module', 'Import')
            ->where('action', 'Client Imported')
            ->whereIn('client_id', $added->pluck('id'))
            ->pluck('client_id')
            ->flip();
        $addedByUser = $added->reject(fn ($c) => $imported->has($c->id))->groupBy('created_by');

        // The work itself.
        $upkeep = ActivityLog::query()
            ->whereIn('user_id', $ids)
            ->whereNotNull('client_id')
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($q) {
                foreach (self::CLIENT_UPKEEP_ACTIONS as $module => $actions) {
                    $q->orWhere(fn ($m) => $m->where('module', $module)->whereIn('action', $actions));
                }
            })
            ->get(['user_id', 'client_id', 'created_at'])
            ->groupBy('user_id');

        $result = [];
        foreach ($ids as $userId) {
            $userId   = (int) $userId;
            $mine     = $portfolioByUser->get($userId, collect());
            $mineIds  = $mine->pluck('id')->flip();
            $active   = $mine->filter(fn ($c) => in_array($c->client_status, self::ACTIVE_CLIENT_STATUSES, true));
            $addedN   = $addedByUser->get($userId, collect())->count();

            // Distinct working days per client, on their own clients only.
            $daysByClient = $upkeep->get($userId, collect())
                ->filter(fn ($log) => $mineIds->has($log->client_id))
                ->groupBy('client_id')
                ->map(fn ($logs) => $logs->map(fn ($log) => $log->created_at->toDateString())->unique()->count());

            $upkeepDays = (int) $daysByClient->sum(fn ($days) => min($days, self::UPKEEP_DAYS_CAP_PER_CLIENT));

            if ($active->isEmpty() && $addedN === 0 && $upkeepDays === 0) {
                $result[$userId] = null;
                continue;
            }

            $points   = $addedN * self::CLIENT_ADDED_POINTS + $upkeepDays;
            $activity = round(min(100, $points / $target * 100), 2);
            $covered  = $active->filter(fn ($c) => $daysByClient->has($c->id))->count();
            $coverage = $active->isNotEmpty() ? round($covered / $active->count() * 100, 2) : null;

            $result[$userId] = [
                'clients_added'       => $addedN,
                'clients_total'       => $mine->count(),
                'clients_active'      => $active->count(),
                'active_maintained'   => $covered,
                'clients_maintained'  => $daysByClient->count(),
                'upkeep_days'         => $upkeepDays,
                'points'              => $points,
                'target_points'       => $target,
                'activity_pct'        => $activity,
                'coverage_pct'        => $coverage,
                'score'               => $coverage === null ? $activity : round(($coverage + $activity) / 2, 2),
            ];
        }

        return $result;
    }

    public function resolveWeights(User $user): KpiWeightConfig
    {
        // Resolved in-memory from the memoized set (employee → department →
        // global precedence) so we don't run up to 3 queries per employee.
        $configs = $this->weightConfigs();

        $employeeConfig = $configs->first(fn (KpiWeightConfig $c) => $c->scope_type === KpiWeightConfig::SCOPE_EMPLOYEE && $c->scope_value === (string) $user->id);
        if ($employeeConfig) {
            return $employeeConfig;
        }

        $roleNames = $user->getRoleNames();
        if ($roleNames->isNotEmpty()) {
            $departmentConfig = $configs->first(fn (KpiWeightConfig $c) => $c->scope_type === KpiWeightConfig::SCOPE_DEPARTMENT && $roleNames->contains($c->scope_value));
            if ($departmentConfig) {
                return $departmentConfig;
            }
        }

        return $configs->first(fn (KpiWeightConfig $c) => $c->scope_type === KpiWeightConfig::SCOPE_GLOBAL)
            ?? new KpiWeightConfig([
                'scope_type' => KpiWeightConfig::SCOPE_GLOBAL,
                'task_completion_weight' => 20, 'on_time_weight' => 20, 'revision_weight' => 15,
                'sales_weight' => 15, 'satisfaction_weight' => 15, 'client_care_weight' => 15,
            ]);
    }

    public function finalScore(User $user, string $period): array
    {
        $taskCompletion = $this->taskCompletion($user, $period);
        $onTime         = $this->onTimeCompletion($user, $period);
        $revision       = $this->revisionRate($user, $period);
        $sales          = $this->salesAchievement($user, $period);
        $satisfaction   = $this->clientSatisfaction($user, $period);
        $clientCare     = $this->clientCare($user, $period);

        $weightConfig = $this->resolveWeights($user);
        $weights = $weightConfig->toWeightsArray();

        $scores = [
            'task_completion' => $taskCompletion['completion_pct'],
            'on_time'         => $onTime['on_time_rate'],
            'revision'        => $revision['revision_rate_kpi'] !== null ? round(100 - $revision['revision_rate_kpi'], 2) : null,
            'sales'           => $sales !== null ? min($sales['pct'] ?? 0, 100) : null,
            'satisfaction'    => $satisfaction['score'] ?? null,
            'client_care'     => $clientCare['score'] ?? null,
        ];

        // A KPI counts when there is data for it and its profile gives it weight;
        // one weighted 0 is shown but can neither lift nor sink the score.
        $applicable = array_filter($scores, fn ($v, $key) => $v !== null && ($weights[$key] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);

        if (empty($applicable)) {
            return [
                'final_score' => null, 'performance_level' => null,
                'scores' => $scores, 'weights_used' => [], 'strongest' => null, 'weakest' => null,
                'components' => compact('taskCompletion', 'onTime', 'revision', 'sales', 'satisfaction', 'clientCare'),
            ];
        }

        $weightSum = 0;
        foreach (array_keys($applicable) as $key) {
            $weightSum += $weights[$key];
        }

        $weightedTotal = 0;
        $weightsUsed = [];
        foreach ($applicable as $key => $score) {
            $normalizedWeight = $weightSum > 0 ? $weights[$key] / $weightSum * 100 : 0;
            $weightsUsed[$key] = round($normalizedWeight, 2);
            $weightedTotal += $score * $normalizedWeight / 100;
        }

        $finalScore = round($weightedTotal, 2);

        arsort($applicable);
        $strongest = array_key_first($applicable);
        $weakest = array_key_last($applicable);

        return [
            'final_score' => $finalScore,
            'performance_level' => $this->performanceLevel($finalScore),
            'scores' => $scores,
            'weights_used' => $weightsUsed,
            'strongest' => $strongest,
            'weakest' => $weakest,
            'components' => compact('taskCompletion', 'onTime', 'revision', 'sales', 'satisfaction', 'clientCare'),
        ];
    }

    public function performanceLevel(float $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 80 => 'Very Good',
            $score >= 70 => 'Good',
            $score >= 60 => 'Needs Improvement',
            default      => 'Poor',
        };
    }
}
