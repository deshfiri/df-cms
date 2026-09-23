<?php

namespace App\Services;

use App\Models\FlowTransition;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * One person's workload, and what they got done today, this week and this month.
 *
 * ─── Definitions ────────────────────────────────────────────────────────────
 *  Right now (current state, not a period):
 *     assigned         open tasks assigned to them (not submitted, completed or cancelled)
 *     active           of those, In Progress
 *     pending          of those, not started or on hold
 *     awaiting_review  tasks they submitted that nobody has ruled on yet
 *     to_review        tasks others handed in to them
 *     overdue          their open tasks past the deadline, plus workflow items they hold past due
 *     flow_mine        workflow items they have claimed
 *     flow_available   workflow items waiting, unclaimed, at their stages
 *
 *  Per period (today / this week / this month):
 *     completed        tasks assigned to them that were completed in the period
 *                      (by completed_at), plus workflow stages they handed forward
 *     submitted        times they submitted a task (a resubmission after rework counts)
 *     received         tasks assigned to them in the period, at creation or by reassignment
 *
 * ─── Time zones ─────────────────────────────────────────────────────────────
 * "Today" is the viewer's today. Periods are cut at the viewer's local
 * midnight (weeks start Monday) and every timestamp is bucketed by its local
 * date — so a task finished at 01:00 in Dhaka counts for that Dhaka day, even
 * though it is still the previous day in UTC. Bucketing happens in PHP, so it
 * does not depend on the database's time zone tables.
 */
class MyWorkService
{
    public const SERIES_DAYS = 14;

    public function __construct(
        private readonly FlowService $flows,
    ) {}

    /** A browser-supplied IANA zone, or the application's when it is not one. */
    public static function timezone(?string $zone): string
    {
        return $zone && in_array($zone, DateTimeZone::listIdentifiers(), true)
            ? $zone
            : (string) config('app.timezone');
    }

    /** @return array<string,mixed> */
    public function summary(User $user, string $zone): array
    {
        $now  = CarbonImmutable::now($zone);
        $from = [
            'today' => $now->startOfDay(),
            'week'  => $now->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $now->startOfMonth(),
        ];
        $seriesFrom = $now->startOfDay()->subDays(self::SERIES_DAYS - 1);
        // Everything below is loaded once, from the earliest moment any view needs.
        $since = ($seriesFrom->lt($from['month']) ? $seriesFrom : $from['month'])->setTimezone(config('app.timezone'));

        $events = [
            'completed' => $this->completedTaskTimes($user, $since)->merge($this->flowHandoffTimes($user, $since)),
            'submitted' => $this->submissionTimes($user, $since),
            'received'  => $this->receivedTimes($user, $since),
        ];

        // Local calendar day of every event.
        $days = array_map(
            fn (Collection $times) => $times->map(fn ($at) => CarbonImmutable::parse($at)->setTimezone($zone)->toDateString())->countBy()->all(),
            $events,
        );

        $periods = [];
        foreach ($from as $period => $start) {
            foreach ($events as $kind => $times) {
                $periods[$period][$kind] = $times->filter(fn ($at) => CarbonImmutable::parse($at)->gte($start))->count();
            }
        }

        $labels = [];
        $series = ['completed' => [], 'submitted' => [], 'received' => []];
        for ($day = $seriesFrom; $day->lte($now); $day = $day->addDay()) {
            $key = $day->toDateString();
            $labels[] = $key;
            foreach ($series as $kind => $_) {
                $series[$kind][] = $days[$kind][$key] ?? 0;
            }
        }

        return [
            'timezone'     => $zone,
            'generated_at' => $now->toIso8601String(),
            'now'          => $this->currentLoad($user),
            'periods'      => $periods,
            'period_start' => array_map(fn (CarbonImmutable $d) => $d->toDateString(), $from),
            'series'       => ['labels' => $labels] + $series,
        ];
    }

    /** @return array<string,int> */
    private function currentLoad(User $user): array
    {
        $byStatus = Task::whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
            ->select('status')->selectRaw('COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->map(fn ($n) => (int) $n);

        $queue    = $this->flows->myQueue($user);
        $flowMine = $queue->filter(fn ($item) => (int) $item->assigned_to === (int) $user->id);

        $overdueTasks = Task::whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))->overdue()->count();
        $overdueFlow  = $flowMine->filter(fn ($item) => $item->isOverdue())->count();

        return [
            'assigned'        => (int) $byStatus->except(Task::$settledStatuses)->sum(),
            'active'          => $byStatus->get('In Progress', 0),
            // 'Overdue' is a legacy stored status: work that was never started.
            'pending'         => $byStatus->get('Pending', 0) + $byStatus->get('On Hold', 0) + $byStatus->get('Overdue', 0),
            'awaiting_review' => $byStatus->get(Task::STATUS_SUBMITTED, 0),
            'completed_total' => $byStatus->get('Completed', 0),
            'to_review'       => Task::where('created_by', $user->id)
                ->whereDoesntHave('assignees', fn ($q) => $q->where('users.id', $user->id))
                ->where('status', Task::STATUS_SUBMITTED)
                ->count(),
            'overdue'         => $overdueTasks + $overdueFlow,
            'overdue_tasks'   => $overdueTasks,
            'overdue_flow'    => $overdueFlow,
            'flow_mine'       => $flowMine->count(),
            'flow_available'  => $queue->whereNull('assigned_to')->count(),
            // Whether they work any workflow stage at all — no point showing zeros otherwise.
            'flow_participant' => (bool) $this->flows->navSummary($user)['participant'],
        ];
    }

    private function completedTaskTimes(User $user, CarbonImmutable $since): Collection
    {
        return Task::whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
            ->where('status', 'Completed')
            ->where('completed_at', '>=', $since)
            ->pluck('completed_at');
    }

    /** Same rule as the department dashboard: a forward move or a finish — never a send-back or a cancellation. */
    private function flowHandoffTimes(User $user, CarbonImmutable $since): Collection
    {
        return FlowTransition::query()
            ->from('flow_transitions as t')
            ->leftJoin('flow_stages as fs', 'fs.id', '=', 't.from_stage_id')
            ->leftJoin('flow_stages as ts', 'ts.id', '=', 't.to_stage_id')
            ->where('t.moved_by', $user->id)
            ->whereNotNull('t.from_stage_id')
            ->where('t.created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('t.note')->orWhere('t.note', 'not like', 'Cancelled%'))
            ->where(fn ($q) => $q->whereNull('t.to_stage_id')->orWhereColumn('ts.position', '>', 'fs.position'))
            ->pluck('t.created_at');
    }

    private function submissionTimes(User $user, CarbonImmutable $since): Collection
    {
        return TaskActivity::where('user_id', $user->id)
            ->where('event', 'submitted')
            ->where('created_at', '>=', $since)
            ->pluck('created_at');
    }

    /**
     * Assignment moments, read from the task history. Filtered in PHP rather
     * than with a JSON path in SQL: ids in `meta` may be stored as numbers or
     * strings, which SQLite and MySQL compare differently.
     *
     * Only counts activity logged since this feature shipped — older rows
     * still carry the single-assignee `assigned_to`/`to` meta keys, which
     * this no longer reads (same trade-off made for every other event-meta
     * shape change: no data lost, only a gap in this rolling chart for
     * history predating it).
     */
    private function receivedTimes(User $user, CarbonImmutable $since): Collection
    {
        return TaskActivity::whereIn('event', ['created', 'reassigned'])
            ->where('created_at', '>=', $since)
            ->get(['event', 'meta', 'created_at'])
            ->filter(function (TaskActivity $a) use ($user) {
                $ids = $a->meta[$a->event === 'created' ? 'assignee_ids' : 'added'] ?? [];

                return in_array((int) $user->id, array_map('intval', (array) $ids), true);
            })
            ->pluck('created_at');
    }
}
