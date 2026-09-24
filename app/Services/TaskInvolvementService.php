<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskInvolvement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who actually worked on a task, and how much of it is theirs.
 *
 * ─── Why this exists ────────────────────────────────────────────────────────
 * Performance used to credit a task entirely to whoever held it at the end. A
 * task that passed through someone's hands gave them nothing, and one that
 * landed on someone at the last minute gave them everything. Credit now follows
 * what each person actually did.
 *
 * ─── Source of truth ────────────────────────────────────────────────────────
 * The task's activity log (task_activities). Involvement rows are a projection
 * of that log, rebuilt from it after every new activity — so every number here
 * can be traced to the logged events that produced it, and rebuilding any task
 * reproduces exactly the same result. Opening or viewing a task is never logged
 * and so never counts.
 *
 * ─── The formula ────────────────────────────────────────────────────────────
 * Work points, per person, per task (WORK_POINTS, capped per person by CAPS):
 *     started work        2    (first time only; later starts are "resumed")
 *     resumed work        0.5  (up to 1)
 *     added a file        2    (up to 6)
 *     commented           0.5  (up to 1.5)
 *     shared a link/note  0.5  (up to 1.5)
 *     submitted the work  4    (up to 8 — a resubmission after rework counts)
 *
 * A task with more than one current assignee needs every one of them to
 * submit their own part before it reaches Submitted (see TaskService::
 * submitForReview() and Task::STATUS_PARTIALLY_SUBMITTED) — each
 * submission earns 4 ÷ however many are currently assigned, so the total
 * "submitted" pool stays one task's worth whether one person hands it in
 * alone or several each do. Every other event still earns in full
 * regardless of assignee count.
 *
 * Comments, files and links earn work points only for people doing the work: anyone
 * who has held the task, or a helper who neither asked for it nor reviewed it.
 * A manager commenting on work they assigned is not doing that work.
 *
 * The current holder also gets HOLDING_POINTS (1) while they hold it: being
 * responsible for finishing is part of the work.
 *
 * Work share (0–1) = a doer's points ÷ the sum over all doers (primary +
 * contributors). With nobody holding it and nobody having worked on it, there
 * is no share to give. Review is recorded separately (REVIEW_POINTS) and never
 * dilutes the doers' shares.
 *
 * ─── Roles ──────────────────────────────────────────────────────────────────
 *     primary         holds the task now
 *     contributor     earned work points (an earlier holder, or a helper)
 *     reviewer        approved or returned it, and did no work on it
 *     passed_through  held it at some point without doing anything
 *     creator         asked for it, nothing more
 *     other           took part in some other way (e.g. a comment by a manager)
 */
class TaskInvolvementService
{
    public const ROLE_PRIMARY        = 'primary';
    public const ROLE_CONTRIBUTOR    = 'contributor';
    public const ROLE_REVIEWER       = 'reviewer';
    public const ROLE_PASSED_THROUGH = 'passed_through';
    public const ROLE_CREATOR        = 'creator';
    public const ROLE_OTHER          = 'other';

    /** Roles whose points divide the task's work credit. */
    public const DOER_ROLES = [self::ROLE_PRIMARY, self::ROLE_CONTRIBUTOR];

    public const WORK_POINTS = [
        'started'          => 2.0,
        'resumed'          => 0.5,
        'attachment_added' => 2.0,
        'comment'          => 0.5,
        'note_added'       => 0.5,
        'submitted'        => 4.0,
    ];

    /** The most of each one person can earn on one task — volume is not effort. */
    public const CAPS = [
        'resumed'          => 1.0,
        'attachment_added' => 6.0,
        'comment'          => 1.5,
        'note_added'       => 1.5,
        'submitted'        => 8.0,
    ];

    public const REVIEW_POINTS = [
        'approved' => 2.0,
        'returned' => 2.0,
    ];

    public const HOLDING_POINTS = 1.0;

    /** Recompute one task's involvement from its activity log. */
    public function rebuild(Task $task): void
    {
        $rows = $this->project($task->loadMissing('assignees:id'), TaskActivity::where('task_id', $task->id)->orderBy('id')->get());

        DB::transaction(function () use ($task, $rows) {
            TaskInvolvement::where('task_id', $task->id)->delete();

            foreach ($rows as $row) {
                TaskInvolvement::create(['task_id' => $task->id] + $row);
            }
        });
    }

    /**
     * Work shares by user id, from a task's involvement rows.
     *
     * @param  iterable<TaskInvolvement|array>  $involvements
     * @return array<int,float>
     */
    public static function workShares(iterable $involvements): array
    {
        $weights = [];

        foreach ($involvements as $inv) {
            $role = data_get($inv, 'role');
            if (!in_array($role, self::DOER_ROLES, true)) {
                continue;
            }

            $weight = (float) data_get($inv, 'points') + ($role === self::ROLE_PRIMARY ? self::HOLDING_POINTS : 0.0);
            if ($weight > 0) {
                $weights[(int) data_get($inv, 'user_id')] = $weight;
            }
        }

        $total = array_sum($weights);
        if ($total <= 0) {
            return [];
        }

        return array_map(fn ($w) => round($w / $total, 4), $weights);
    }

    /**
     * Replay a task's history into one row per person.
     *
     * @param  Collection<int,TaskActivity>  $activities  in the order they happened
     * @return array<int,array<string,mixed>>
     */
    public function project(Task $task, Collection $activities): array
    {
        $people = [];
        $currentAssigneeIds = $task->assignees->pluck('id')->map(fn ($id) => (int) $id)->all();

        $person = function (?int $userId) use (&$people): ?int {
            if (!$userId) {
                return null;
            }
            $people[$userId] ??= [
                'points' => 0.0, 'review_points' => 0.0, 'events_count' => 0, 'earned' => [],
                'first_activity_at' => null, 'last_activity_at' => null,
                'assigned_at' => null, 'released_at' => null, 'was_assignee' => false,
            ];

            return $userId;
        };

        $take = function (int $userId, ?Carbon $at) use (&$people) {
            $people[$userId]['assigned_at'] = $at;
            $people[$userId]['released_at'] = null;
            $people[$userId]['was_assignee'] = true;
        };

        // A shared task's "submitted" points are split across however many
        // people currently hold it: each one only submits their own part, so
        // the pool one submission is worth stays the same whether one person
        // does it alone or several each submit theirs — not multiplied by
        // headcount. Everything else earns in full regardless of assignee
        // count; only submitting is inherently "the whole task's worth",
        // divided among however many now have to each do it.
        $earn = function (int $userId, string $event, int $divisor = 1) use (&$people) {
            if ($event === 'started' && ($people[$userId]['earned']['started'] ?? 0) > 0) {
                $event = 'resumed';
            }

            $points = (self::WORK_POINTS[$event] ?? 0.0) / max(1, $divisor);
            $so_far = $people[$userId]['earned'][$event] ?? 0.0;
            $award  = isset(self::CAPS[$event]) ? max(0.0, min($points, self::CAPS[$event] - $so_far)) : $points;

            $people[$userId]['earned'][$event] = $so_far + $award;
            $people[$userId]['points'] += $award;
        };

        // Legacy history has no explicit first assignment. Its first holder is
        // whoever the first recorded hand-off moved the task away from, and
        // failing that, whoever holds it now (a pre-multi-assignee task has
        // exactly one).
        $firstHolder = null;
        foreach ($activities as $activity) {
            [$event, $meta] = self::eventOf($activity);
            if ($event === 'reassigned' && array_key_exists('from', $meta)) {
                $firstHolder = $meta['from'];
                break;
            }
        }
        $firstHolder ??= $currentAssigneeIds[0] ?? null;

        foreach ($activities as $activity) {
            [$event, $meta] = self::eventOf($activity);
            $at    = $activity->created_at;
            $actor = $person($activity->user_id);

            switch ($event) {
                case 'created':
                    // New-shape events carry every assignee set at creation;
                    // old ones (and the legacy free-text inference below)
                    // carry a single `assigned_to`.
                    $holders = array_key_exists('assignee_ids', $meta)
                        ? (array) $meta['assignee_ids']
                        : (array_key_exists('assigned_to', $meta) ? [$meta['assigned_to']] : [$firstHolder]);
                    foreach ($holders as $holderId) {
                        if ($holder = $person($holderId ? (int) $holderId : null)) {
                            $take($holder, $at);
                        }
                    }
                    break;

                case 'reassigned':
                    // New-shape events carry {added, removed}; old ones carry {from, to}.
                    $removed = array_key_exists('removed', $meta) ? (array) $meta['removed'] : array_filter([$meta['from'] ?? null]);
                    $added   = array_key_exists('added', $meta) ? (array) $meta['added'] : array_filter([$meta['to'] ?? null]);

                    foreach ($removed as $removedId) {
                        if (($from = $person($removedId ? (int) $removedId : null)) && $people[$from]['was_assignee']) {
                            $people[$from]['released_at'] = $at;
                        }
                    }
                    foreach ($added as $addedId) {
                        if ($to = $person($addedId ? (int) $addedId : null)) {
                            $take($to, $at);
                        }
                    }
                    break;

                case 'status_changed':
                    if ($actor && $people[$actor]['was_assignee'] && ($meta['to'] ?? null) === 'In Progress') {
                        $earn($actor, 'started');
                    }
                    break;

                case 'submitted':
                    if ($actor) {
                        $earn($actor, 'submitted', max(1, count($currentAssigneeIds)));
                    }
                    break;

                case 'comment':
                case 'attachment_added':
                case 'note_added':
                    // Doing the work, or helping with it — not directing it.
                    $isHelper = $actor
                        && (int) $actor !== (int) $task->created_by
                        && $people[$actor]['review_points'] <= 0;
                    if ($actor && ($people[$actor]['was_assignee'] || $isHelper)) {
                        $earn($actor, $event);
                    }
                    break;

                case 'approved':
                case 'returned':
                    if ($actor) {
                        $people[$actor]['review_points'] += self::REVIEW_POINTS[$event];
                    }
                    break;
            }

            if ($actor) {
                $people[$actor]['events_count']++;
                $people[$actor]['first_activity_at'] ??= $at;
                $people[$actor]['last_activity_at'] = $at;
            }
        }

        // Whoever holds it now is authoritative, whatever the history says —
        // every current assignee, not just one.
        foreach ($currentAssigneeIds as $holderId) {
            $holder = $person($holderId);
            if (!$people[$holder]['was_assignee']) {
                $take($holder, $task->created_at);
            }
            $people[$holder]['released_at'] = null;
        }
        $person($task->created_by ? (int) $task->created_by : null);

        $rows = [];
        foreach ($people as $userId => $p) {
            $role = match (true) {
                in_array((int) $userId, $currentAssigneeIds, true) => self::ROLE_PRIMARY,
                $p['points'] > 0                           => self::ROLE_CONTRIBUTOR,
                $p['review_points'] > 0                    => self::ROLE_REVIEWER,
                $p['was_assignee']                         => self::ROLE_PASSED_THROUGH,
                (int) $userId === (int) $task->created_by  => self::ROLE_CREATOR,
                default                                    => self::ROLE_OTHER,
            };

            $rows[$userId] = [
                'user_id'           => (int) $userId,
                'role'              => $role,
                'points'            => round($p['points'], 2),
                'review_points'     => round($p['review_points'], 2),
                'events_count'      => $p['events_count'],
                'assigned_at'       => $p['assigned_at'],
                'released_at'       => $p['released_at'],
                'first_activity_at' => $p['first_activity_at'],
                'last_activity_at'  => $p['last_activity_at'],
                'breakdown'         => array_map(fn ($v) => round($v, 2), $p['earned']),
            ];
        }

        $shares = self::workShares($rows);
        foreach ($rows as $userId => &$row) {
            $row['share'] = $shares[$userId] ?? 0.0;
        }

        return array_values($rows);
    }

    /**
     * An activity's event and details — structured for anything logged now,
     * inferred from the old free-text action for history logged before events
     * existed, so old tasks count by the same rules as new ones.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function eventOf(TaskActivity $activity): array
    {
        if ($activity->event) {
            return [$activity->event, (array) ($activity->meta ?? [])];
        }

        $old = json_decode((string) $activity->old_value, true);
        $new = json_decode((string) $activity->new_value, true);

        return match ($activity->action) {
            'Created'            => ['created', []],
            'Updated'            => is_array($old) && is_array($new) && ($old['assigned_to'] ?? null) != ($new['assigned_to'] ?? null)
                                        ? ['reassigned', ['from' => $old['assigned_to'] ?? null, 'to' => $new['assigned_to'] ?? null]]
                                        : ['updated', []],
            'Status Changed'     => ['status_changed', [
                                        'from' => trim((string) strstr((string) $activity->description, '→', true)) ?: null,
                                        'to'   => trim((string) substr((string) strrchr((string) $activity->description, '→'), strlen('→'))) ?: null,
                                    ]],
            'Submitted'          => ['submitted', []],
            'Approved'           => ['approved', []],
            'Revision Requested' => ['returned', []],
            'Comment Added'      => ['comment', []],
            'Attachment Added'   => ['attachment_added', []],
            'Attachment Removed' => ['attachment_removed', []],
            default              => ['updated', []],
        };
    }
}
