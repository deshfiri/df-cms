<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fills the task lifecycle timestamps for tasks that predate them.
 *
 * Nothing is invented. Each value comes from something already recorded:
 *  - due_at       — the due date, at the end of that day (the meaning a date-only
 *                   deadline always had: late from the next day);
 *  - started_at   — the first time the task's history shows it moving to In Progress;
 *  - completed_at — when the submission was accepted, else the completion date.
 *
 * Tasks whose history does not say (started before activity was tracked) keep
 * null rather than a guess. Safe to run more than once: it only fills blanks.
 */
class TaskLifecycleBackfill
{
    public static function run(): int
    {
        $updated = 0;

        DB::table('tasks')->orderBy('id')->chunkById(500, function ($tasks) use (&$updated) {
            $history = DB::table('task_activities')
                ->whereIn('task_id', $tasks->pluck('id'))
                ->whereIn('action', ['Status Changed', 'Approved'])
                ->orderBy('id')
                ->get(['task_id', 'action', 'description', 'created_at'])
                ->groupBy('task_id');

            foreach ($tasks as $task) {
                $events  = $history->get($task->id, collect());
                $changes = [];

                if ($task->due_date && !$task->due_at) {
                    $changes['due_at'] = Carbon::parse($task->due_date)->setTime(23, 59, 59);
                }

                if (!$task->started_at) {
                    $started = $events->first(fn ($e) => $e->action === 'Status Changed'
                        && str_ends_with((string) $e->description, 'In Progress'));
                    if ($started) {
                        $changes['started_at'] = $started->created_at;
                    }
                }

                if (!$task->completed_at && $task->status === 'Completed') {
                    $accepted = $events->last(fn ($e) => $e->action === 'Approved');

                    if ($accepted) {
                        $changes['completed_at'] = $accepted->created_at;
                    } elseif ($task->completion_date) {
                        $day = Carbon::parse($task->completion_date);
                        $touched = $task->updated_at ? Carbon::parse($task->updated_at) : null;
                        // The last save that day is the best evidence of when it finished.
                        $changes['completed_at'] = $touched && $touched->isSameDay($day) ? $touched : $day->setTime(23, 59, 59);
                    }
                }

                if ($changes) {
                    DB::table('tasks')->where('id', $task->id)->update($changes);
                    $updated++;
                }
            }
        });

        return $updated;
    }
}
