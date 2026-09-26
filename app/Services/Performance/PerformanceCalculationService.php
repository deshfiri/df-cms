<?php

namespace App\Services\Performance;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientSatisfactionRating;
use App\Models\DailyTarget;
use App\Models\FlowItem;
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
    /** @var array<int,\Illuminate\Support\Collection> */
    private array $tasksGivenByUser = [];
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

        // A separate set: tasks this person *gave out* (created), for Task
        // Giving Quality — the mirror of the set above, keyed by created_by
        // and scoped by created_at rather than due_date.
        $this->tasksGivenByUser = $this->loadTasksGiven($ids, $period);

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

        // Standing per-user goals, not period-scoped, so one load covers every
        // period this cohort is ever scored for in the same request. A user
        // may have a target on more than one scope, hence groupBy not keyBy.
        $this->dailyTargetsByUser = DailyTarget::whereIn('user_id', $ids)->get()->groupBy('user_id')->all();

        $this->flowItemsByUser = $this->loadFlowItems($ids, $period);

        // Standing sizes, not period-scoped, so one load covers this cohort
        // for every period scored in the same request.
        $this->clientPortfolioByUser = $this->loadClientPortfolios($ids);
        $this->portfoliosPrefetched  = true;

        $this->prefetchPeriod = $period;
    }

    /** @var array<int,array<string,mixed>> */
    private array $clientCareByUser = [];

    /** @var array<int,\Illuminate\Support\Collection<int,DailyTarget>> */
    private array $dailyTargetsByUser = [];

    /** @var array<int,\Illuminate\Support\Collection<int,FlowItem>> */
    private array $flowItemsByUser = [];

    /**
     * The most any one person in the whole company did, per scope, in a
     * period — for Output Volume. Independent of prefetch()'s cohort (which
     * may be department-filtered): this always means company-wide, so the
     * same person's Output Volume score never changes depending on which
     * filtered view triggered the calculation. Memoized per period per
     * service instance, since a scoreboard run scores many people against
     * the same ones.
     *
     * @var array<string,float>
     */
    private array $cohortMaxWorkflowByPeriod = [];

    private ?float $cohortMaxClientPortfolioCache = null;

    /** Standing client-portfolio size per user, for Output Volume's "client handling" scope. */
    private array $clientPortfolioByUser = [];

    /** True once prefetch() has loaded $clientPortfolioByUser for the cohort — see clientPortfolioSize(). */
    private bool $portfoliosPrefetched = false;

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

    /**
     * Tasks this employee gave out (created) whose brief was written in the
     * period — from the cohort load when there is one, otherwise fetched for
     * them alone. For Task Giving Quality; see taskGivingQuality().
     */
    private function tasksGivenFor(User $user, string $period): \Illuminate\Support\Collection
    {
        if ($this->prefetched($period)) {
            return $this->tasksGivenByUser[$user->id] ?? collect();
        }

        return $this->loadTasksGiven(collect([$user->id]), $period)[$user->id] ?? collect();
    }

    /**
     * @param  \Illuminate\Support\Collection<int,int>  $ids
     * @return array<int,\Illuminate\Support\Collection<int,Task>>
     */
    private function loadTasksGiven(\Illuminate\Support\Collection $ids, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        return Task::query()
            ->whereIn('created_by', $ids)
            ->whereBetween('created_at', [$start, $end])
            ->withCount(['revisions as giver_mistake_count' => fn ($q) => $q->where('reason_category', 'Task Giver Mistake')])
            ->get(['id', 'created_by', 'created_at'])
            ->groupBy('created_by')
            ->all();
    }

    /**
     * Of the tasks this person gave out (created) this period, what share
     * came back clean rather than needing a "Task Giver Mistake" revision —
     * missing information, an unclear brief, and the like. The mirror of
     * Quality/revisionRate(), scored against whoever wrote the brief rather
     * than whoever did the work.
     *
     * A "Task Giver Mistake" revision never counts against the assignee's
     * own revision-rate KPI — only "Employee Mistake" does (revisionRate()'s
     * eager-loaded revisions are filtered to that category alone) — so
     * nobody is penalised twice, or for someone else's mistake.
     *
     * Nobody who gave out nothing this period is scored on it at all — same
     * "no data, no penalty" rule as every other optional KPI.
     */
    public function taskGivingQuality(User $user, string $period): ?array
    {
        $tasks = $this->tasksGivenFor($user, $period);

        if ($tasks->isEmpty()) {
            return null;
        }

        $totalGiven     = $tasks->count();
        $flawed         = $tasks->where('giver_mistake_count', '>', 0)->count();
        $cleanFirstTime = $totalGiven - $flawed;

        return [
            'total_given'      => $totalGiven,
            'clean_first_time' => $cleanFirstTime,
            'flawed'           => $flawed,
            'pct'              => round($cleanFirstTime / $totalGiven * 100, 2),
        ];
    }

    /**
     * The employee's workflow (Flow) items due in the period, for the Daily
     * Target "workflow" scope — from the cohort load when there is one,
     * otherwise fetched for them alone.
     */
    private function flowItemsFor(User $user, string $period): \Illuminate\Support\Collection
    {
        if ($this->prefetched($period)) {
            return $this->flowItemsByUser[$user->id] ?? collect();
        }

        return $this->loadFlowItems(collect([$user->id]), $period)[$user->id] ?? collect();
    }

    /**
     * @param  \Illuminate\Support\Collection<int,int>  $ids
     * @return array<int,\Illuminate\Support\Collection<int,FlowItem>>
     */
    private function loadFlowItems(\Illuminate\Support\Collection $ids, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        // assigned_to is who currently holds an open item; it's cleared to
        // null the moment one completes (see FlowService::advance()), so a
        // finished item is only findable by completed_by from then on —
        // matching whichever of the two is actually set.
        return FlowItem::query()
            ->where(fn ($q) => $q->whereIn('assigned_to', $ids)->orWhereIn('completed_by', $ids))
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'assigned_to', 'completed_by', 'due_date', 'status'])
            ->groupBy(fn (FlowItem $item) => $item->completed_by ?? $item->assigned_to)
            ->all();
    }

    // ── Task credit ──────────────────────────────────────────────────────
    //
    // A task counts for a person in proportion to the work they did on it, as
    // recorded by TaskInvolvementService: whoever holds it now and anyone who
    // contributed share it by their work points. Whoever reviewed it — approved
    // or sent it back — earns their own full, independent share too, on top of
    // the doers' pool rather than out of it (see workSharesOf()). Someone it
    // merely passed through, or whoever created it, still gets no share, so
    // that neither helps nor hurts their task KPIs.
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
                ->whereHas('assignees', fn ($aq) => $aq->whereIn('users.id', $ids))
                ->orWhereHas('involvements', fn ($inv) => $inv->whereIn('user_id', $ids)
                    ->where(fn ($iq) => $iq->where('points', '>', 0)->orWhere('review_points', '>', 0))))
            // Every count and relation here deliberately excludes "Task Giver
            // Mistake" revisions: that reason exists precisely so a mistake
            // in the brief is never held against the person who did the
            // work — not in the KPI rate, and not in any of the informational
            // counts revisionRate() shows alongside it. See taskGivingQuality().
            ->withCount([
                'revisions as revisions_count' => fn ($q) => $q->where('reason_category', '!=', 'Task Giver Mistake'),
                // For creditOf()'s client-count multiplier.
                'clients',
            ])
            ->with([
                'revisions' => fn ($q) => $q->where('reason_category', 'Employee Mistake'),
                'involvements',
                'assignees:id',
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
     * Each doer's share of one task (loaded with its involvements and assignees),
     * plus a reviewer's own full share on top.
     *
     * Every current assignee is treated as a holder whatever the involvement
     * rows last recorded, so credit follows the task even if something
     * reassigned it without going through TaskService. A task with no
     * involvement recorded at all counts wholly for each current assignee —
     * independently, since a share is a per-(user, task) number, never summed
     * across users — the direct generalization of "solo work has share 1".
     *
     * Reviewing (approving or sending back) earns a full, independent share
     * of its own — it never dilutes the doers' pool above, and a reviewer who
     * also did doer work simply keeps whichever is already 1.0. It feeds Task
     * Completion and On-Time Delivery like any other share; revisionRate()
     * deliberately excludes it (see there) since a reviewer catching someone
     * else's mistake must never look like a mistake on the reviewer's own
     * record.
     *
     * @return array<int,float>
     */
    public static function workSharesOf(Task $task): array
    {
        $holderIds = $task->assignees->pluck('id')->map(fn ($id) => (int) $id)->all();

        $shares = $task->involvements->isEmpty()
            ? array_fill_keys($holderIds, 1.0)
            : self::doerSharesOf($task, $holderIds);

        foreach ($task->involvements as $inv) {
            if ((float) $inv->review_points > 0) {
                $shares[(int) $inv->user_id] = 1.0;
            }
        }

        return $shares;
    }

    /** @param array<int,int> $holderIds */
    private static function doerSharesOf(Task $task, array $holderIds): array
    {
        $rows = $task->involvements->map(fn ($inv) => [
            'user_id' => (int) $inv->user_id,
            'points'  => (float) $inv->points,
            'role'    => match (true) {
                in_array((int) $inv->user_id, $holderIds, true) => TaskInvolvementService::ROLE_PRIMARY,
                (float) $inv->points > 0        => TaskInvolvementService::ROLE_CONTRIBUTOR,
                default                         => $inv->role === TaskInvolvementService::ROLE_PRIMARY
                                                      ? TaskInvolvementService::ROLE_PASSED_THROUGH
                                                      : $inv->role,
            },
        ])->all();

        $seenIds = $task->involvements->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        foreach ($holderIds as $holderId) {
            if (!in_array($holderId, $seenIds, true)) {
                $rows[] = ['user_id' => $holderId, 'points' => 0.0, 'role' => TaskInvolvementService::ROLE_PRIMARY];
            }
        }

        return TaskInvolvementService::workShares($rows);
    }

    /** Whether $task's credit for $user came purely from reviewing it, not from doing any of the work. */
    private static function isReviewOnlyCredit(Task $task, int $userId): bool
    {
        if ($task->assignees->contains($userId)) {
            return false;
        }

        $inv = $task->involvements->first(fn ($i) => (int) $i->user_id === $userId);

        return $inv && (float) $inv->points <= 0 && (float) $inv->review_points > 0;
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
                ->whereHas('assignees', fn ($aq) => $aq->where('users.id', $user->id))
                ->orWhereHas('involvements', fn ($inv) => $inv->where('user_id', $user->id)))
            ->withCount('clients')
            ->with(['involvements', 'assignees:id'])
            ->orderBy('due_date')->orderBy('id')
            ->get(['id', 'title', 'status', 'created_by', 'due_date', 'due_at']);

        return $tasks->map(function (Task $task) use ($user) {
            $mine  = $task->involvements->first(fn ($inv) => (int) $inv->user_id === (int) $user->id);
            $share = self::workSharesOf($task)[$user->id] ?? 0.0;
            // See creditOf() — feeds Task Completion, On-Time Delivery and
            // Revision Rate alike.
            $clientMultiplier = max(1, (int) $task->clients_count);

            return [
                'task_id'   => $task->id,
                'title'     => $task->title,
                'status'    => $task->status,
                'due'       => $task->due_date?->toDateString(),
                'role'      => $task->assignees->contains($user->id)
                                   ? TaskInvolvementService::ROLE_PRIMARY
                                   : ($mine?->role ?? TaskInvolvementService::ROLE_OTHER),
                'points'    => (float) ($mine?->points ?? 0),
                'review_points' => (float) ($mine?->review_points ?? 0),
                'breakdown' => $mine?->breakdown ?? [],
                'share'     => $share,
                'clients_count' => $task->clients_count,
                'client_multiplier' => $clientMultiplier,
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

    /**
     * This user's credit for one task: their share of it, weighted by how
     * many clients it's linked to (Task::clients — the multi-client
     * feature). A task linked to more than one client counts
     * proportionally more — double for 2 clients, triple for 3, and so on
     * — because clearing one task that serves several clients at once is
     * more done for the business than clearing one that serves a single
     * client, even though both are "one task" by count. A task with no
     * client, or exactly one, credits exactly as it always did.
     *
     * Feeds every task KPI that measures how much got done or how well:
     * Task Completion, On-Time Delivery, Revision Rate. Output Volume
     * deliberately excludes tasks entirely (see its own docblock), so it
     * never sees this multiplier either way.
     */
    private static function creditOf(Task $task): float
    {
        return self::shareOf($task) * max(1, (int) ($task->clients_count ?? 1));
    }

    /** Sum of credit — how many whole tasks' worth of credit a set represents. */
    private static function credit(iterable $tasks): float
    {
        $sum = 0.0;
        foreach ($tasks as $task) {
            $sum += self::creditOf($task);
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
            $share = self::creditOf($task);
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
        // sent back regardless of where it ended up. A task credited to this
        // user purely for reviewing it is excluded here — this KPI measures
        // whether YOUR work needed fixing, and a reviewer who correctly sent
        // someone else's task back must never have that look like a mistake
        // on their own record.
        $tasks = $this->tasksFor($user, $period)
            ->reject(fn (Task $t) => self::isReviewOnlyCredit($t, $user->id))
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

    // ── Daily Target ─────────────────────────────────────────────────────
    //
    // An employee's optional, standing "N a day" goal, on any subset of the
    // available scopes at once (DailyTarget — set by whoever holds 'manage
    // performance', Performance → Configuration → Daily Targets). Nobody who
    // was never given one is measured on it at all, so it never pulls down
    // someone whose manager doesn't use the feature.
    //
    // Per scope:
    //   target so far = target/day × days elapsed in the period — the full
    //                   month once it has closed, otherwise up to and
    //                   including today
    //   available     = that scope's items due so far that were actually
    //                   assigned to them — the work that existed to do
    //   completed     = the assigned ones of those already marked done
    //
    //   If available < target so far, there simply wasn't enough work to hit
    //   the goal — that is on the business, not the employee, so the scope
    //   is forgiven and scores 100%. Otherwise it is completed ÷ target so
    //   far × 100, capped at 100 — falling short here is what actually
    //   costs them, same as every other KPI.
    //
    // The overall Daily Target score is the plain average of every scope the
    // employee has a target on.

    public function dailyTargetAchievement(User $user, string $period): ?array
    {
        $targets = $this->prefetched($period)
            ? ($this->dailyTargetsByUser[$user->id] ?? collect())
            : DailyTarget::where('user_id', $user->id)->get();

        if ($targets->isEmpty()) {
            return null;
        }

        [$start, $end] = $this->periodBounds($period);
        $today = now();
        // Whole calendar days: Carbon 3's diffInDays() returns a float, and
        // today counting only once (not 1.5) needs both ends on a day
        // boundary — also the "due so far" cutoff for what counts as available.
        $windowEnd   = ($today->lessThan($end) ? $today : $end)->copy()->startOfDay();
        $elapsedDays = max(1, (int) $start->diffInDays($windowEnd) + 1);

        $ownTasks  = $this->tasksFor($user, $period)->filter(fn (Task $t) => $t->assignees->contains($user->id));
        $flowItems = $this->flowItemsFor($user, $period);

        $scopes = [];
        foreach ($targets as $target) {
            [$available, $completed] = $this->scopeCounts($target->scope, $ownTasks, $flowItems, $windowEnd);
            $targetSoFar = $target->target_quantity * $elapsedDays;
            $forgiven    = $available < $targetSoFar;

            $scopes[$target->scope] = [
                'label'          => DailyTarget::$scopeLabels[$target->scope] ?? $target->scope,
                'target_per_day' => $target->target_quantity,
                'target_so_far'  => $targetSoFar,
                'available'      => $available,
                'completed'      => $completed,
                'forgiven'       => $forgiven,
                'pct'            => $forgiven ? 100.0 : round(min(100, $targetSoFar > 0 ? $completed / $targetSoFar * 100 : 100), 2),
            ];
        }

        return [
            'elapsed_days' => $elapsedDays,
            'scopes'       => $scopes,
            'pct'          => round(collect($scopes)->avg('pct'), 2),
        ];
    }

    /**
     * What was assigned to this employee in a scope, due on or before the
     * window's end, and how much of that they had completed by now.
     *
     * @return array{0:int,1:int}
     */
    private function scopeCounts(string $scope, \Illuminate\Support\Collection $ownTasks, \Illuminate\Support\Collection $flowItems, Carbon $windowEnd): array
    {
        $dueByWindow = fn ($item) => $item->due_date !== null && $item->due_date->lte($windowEnd);

        return match ($scope) {
            DailyTarget::SCOPE_TASK => [
                $ownTasks->filter($dueByWindow)->count(),
                $ownTasks->filter($dueByWindow)->where('status', 'Completed')->count(),
            ],
            DailyTarget::SCOPE_WORKFLOW => [
                $flowItems->filter($dueByWindow)->count(),
                $flowItems->filter($dueByWindow)->where('status', FlowItem::STATUS_COMPLETED)->count(),
            ],
            default => [0, 0],
        };
    }

    // ── Output Volume ────────────────────────────────────────────────────
    //
    // Task Completion (and its like) measure a RATE — finish everything you
    // were given and you hit 100%, whether that was 5 tasks or 50, one
    // workflow item or ten, one client or a dozen. That leaves two people
    // who both cleared their plate looking identical, which undersells
    // whoever actually got through more work. Output Volume measures the
    // absolute output instead, across every scope of work the company
    // tracks, each relative to whoever did the most of it that period:
    //
    //     scope % = my output in that scope ÷ the company's highest × 100
    //
    // capped at 100 (the leader in a scope always scores 100 on it). The
    // overall score is the plain average of whichever scopes this person
    // has anything to measure — same "no data, left out" rule every
    // optional KPI already follows, applied per scope: a Sales-only person
    // with no workflow items due is not scored 0 on workflow, it is simply
    // left out of their average. Nobody with nothing to show in ANY scope
    // is measured on Output Volume at all.
    //
    // Deliberately excludes a "task" scope: Task Completion already scores
    // every task an assignee is given, so a task scope here would credit
    // the exact same completed tasks twice — once for the rate, once for
    // the volume — letting a handful of tasks push both KPIs to 100% at
    // once instead of measuring two genuinely different things.

    public const VOLUME_SCOPE_LABELS = [
        'workflow'         => 'Workflow Items',
        'client_handling'  => 'Client Handling',
    ];

    public function outputVolume(User $user, string $period): ?array
    {
        $scopes = [];

        $flowItems = $this->flowItemsFor($user, $period);
        if ($flowItems->isNotEmpty()) {
            $scopes['workflow'] = $this->volumeScope(
                $flowItems->where('status', FlowItem::STATUS_COMPLETED)->count(),
                $this->cohortMaxWorkflowCompleted($period),
            );
        }

        $myPortfolio = $this->clientPortfolioSize($user);
        if ($myPortfolio !== null) {
            $scopes['client_handling'] = $this->volumeScope($myPortfolio, $this->cohortMaxClientPortfolio());
        }

        if (empty($scopes)) {
            return null;
        }

        foreach ($scopes as $key => $scope) {
            $scopes[$key]['label'] = self::VOLUME_SCOPE_LABELS[$key];
        }

        return [
            'scopes' => $scopes,
            'pct'    => round(collect($scopes)->avg('pct'), 2),
        ];
    }

    /** @return array{mine:float,cohort_max:float,pct:float} */
    private function volumeScope(float $mine, float $cohortMax): array
    {
        return [
            'mine'       => round($mine, 2),
            'cohort_max' => round($cohortMax, 2),
            'pct'        => $cohortMax > 0 ? round(min(100, $mine / $cohortMax * 100), 2) : 0.0,
        ];
    }

    /**
     * The most anyone in the whole company turned in this scope this period
     * — always company-wide and independent of whatever cohort prefetch()
     * was called with, so the number can't shift depending on which
     * filtered scoreboard view triggered the calculation.
     */
    private function cohortMaxWorkflowCompleted(string $period): float
    {
        return $this->cohortMaxWorkflowByPeriod[$period] ??= $this->computeCohortMaxWorkflowCompleted($period);
    }

    private function computeCohortMaxWorkflowCompleted(string $period): float
    {
        [$start, $end] = $this->periodBounds($period);
        $dueWithin = fn ($q) => $q->whereBetween('due_date', [$start->toDateString(), $end->toDateString()]);

        // Whoever currently holds an open item, plus whoever actually
        // finished a completed one — same reasoning as loadFlowItems().
        $ids = $dueWithin(FlowItem::query())->whereNotNull('assigned_to')->distinct()->pluck('assigned_to')
            ->merge($dueWithin(FlowItem::query())->whereNotNull('completed_by')->distinct()->pluck('completed_by'))
            ->unique();

        if ($ids->isEmpty()) {
            return 0.0;
        }

        $max = 0.0;
        foreach ($this->loadFlowItems($ids, $period) as $items) {
            $max = max($max, $items->where('status', FlowItem::STATUS_COMPLETED)->count());
        }

        return $max;
    }

    /**
     * How many clients this person is responsible for right now — assigned
     * to them, or added by them and assigned to no one — the same portfolio
     * Client Care reads, but independent of it: a person can have clients
     * with nothing to show this period is out of Client Care but still
     * counts here on portfolio size alone. Not period-scoped, since a
     * portfolio is a standing assignment, not something that happened in a
     * given month. Null (not zero) when they have no clients of their own.
     */
    private function clientPortfolioSize(User $user): ?int
    {
        $count = $this->portfoliosPrefetched
            ? ($this->clientPortfolioByUser[$user->id] ?? 0)
            : ($this->loadClientPortfolios(collect([$user->id]))[$user->id] ?? 0);

        return $count > 0 ? $count : null;
    }

    private function cohortMaxClientPortfolio(): float
    {
        if ($this->cohortMaxClientPortfolioCache !== null) {
            return $this->cohortMaxClientPortfolioCache;
        }

        $ids = User::where('is_active', true)->pluck('id');
        $counts = $ids->isEmpty() ? [] : $this->loadClientPortfolios($ids);

        return $this->cohortMaxClientPortfolioCache = $counts === [] ? 0.0 : (float) max($counts);
    }

    /**
     * @param  \Illuminate\Support\Collection<int,int>  $ids
     * @return array<int,int>
     */
    private function loadClientPortfolios(\Illuminate\Support\Collection $ids): array
    {
        return Client::query()
            ->where(fn ($q) => $q
                ->whereIn('assigned_to', $ids)
                ->orWhere(fn ($q) => $q->whereNull('assigned_to')->whereIn('created_by', $ids)))
            ->get(['id', 'assigned_to', 'created_by'])
            ->groupBy(fn ($c) => (int) ($c->assigned_to ?? $c->created_by))
            ->map->count()
            ->all();
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
                'task_completion_weight' => 16, 'on_time_weight' => 16, 'revision_weight' => 11,
                'sales_weight' => 10, 'satisfaction_weight' => 10, 'client_care_weight' => 10,
                'daily_target_weight' => 8, 'output_volume_weight' => 9, 'task_giving_weight' => 10,
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
        $dailyTarget    = $this->dailyTargetAchievement($user, $period);
        $outputVolume   = $this->outputVolume($user, $period);
        $taskGiving     = $this->taskGivingQuality($user, $period);

        $weightConfig = $this->resolveWeights($user);
        $weights = $weightConfig->toWeightsArray();

        $scores = [
            'task_completion' => $taskCompletion['completion_pct'],
            'on_time'         => $onTime['on_time_rate'],
            'revision'        => $revision['revision_rate_kpi'] !== null ? round(100 - $revision['revision_rate_kpi'], 2) : null,
            'sales'           => $sales !== null ? min($sales['pct'] ?? 0, 100) : null,
            'satisfaction'    => $satisfaction['score'] ?? null,
            'client_care'     => $clientCare['score'] ?? null,
            'daily_target'    => $dailyTarget['pct'] ?? null,
            'output_volume'   => $outputVolume['pct'] ?? null,
            'task_giving'     => $taskGiving['pct'] ?? null,
        ];

        // A KPI counts when there is data for it and its profile gives it weight;
        // one weighted 0 is shown but can neither lift nor sink the score.
        $applicable = array_filter($scores, fn ($v, $key) => $v !== null && ($weights[$key] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);

        if (empty($applicable)) {
            return [
                'final_score' => null, 'performance_level' => null,
                'scores' => $scores, 'weights_used' => [], 'strongest' => null, 'weakest' => null,
                'components' => compact('taskCompletion', 'onTime', 'revision', 'sales', 'satisfaction', 'clientCare', 'dailyTarget', 'outputVolume', 'taskGiving'),
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
            'components' => compact('taskCompletion', 'onTime', 'revision', 'sales', 'satisfaction', 'clientCare', 'dailyTarget', 'outputVolume', 'taskGiving'),
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
