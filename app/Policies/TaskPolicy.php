<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskDelegationService;

class TaskPolicy
{
    public function __construct(
        private readonly TaskDelegationService $delegation,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['view tasks', 'manage tasks']);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasAnyPermission(['view tasks', 'manage tasks']);
    }

    /**
     * 'manage tasks' as before, plus stage workers who have somebody to
     * delegate to. What they may put in `assigned_to` is then narrowed to
     * their next stage — see TaskDelegationService.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage tasks') || $this->delegation->canDelegate($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->hasPermissionTo('manage tasks');
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasPermissionTo('manage tasks');
    }

    /**
     * The assignee moves their own task between the working statuses.
     *
     * Separate from update(), which is the full edit and needs 'manage tasks'.
     * Someone holding a task must be able to say they have started it without
     * also being able to retitle it, move its deadline, or hand it to someone
     * else — so this is its own, much narrower ability.
     *
     * The statuses it may move *to* are constrained in Task::$workingStatuses:
     * an assignee can start, pause and resume, but cannot mark their own work
     * Completed. That verdict belongs to whoever asked for it.
     */
    public function progress(User $user, Task $task): bool
    {
        return $task->assigned_to === $user->id
            && in_array($task->status, Task::$workingStatuses, true);
    }

    /** Only the person holding the task hands it back for review. */
    public function submit(User $user, Task $task): bool
    {
        return $task->assigned_to === $user->id
            && in_array($task->status, Task::$submittableStatuses, true);
    }

    /**
     * Whoever asked for the work decides whether it is done. Someone with
     * 'manage tasks' can also clear a review so nothing gets stuck behind a
     * person who has left or is away.
     */
    public function review(User $user, Task $task): bool
    {
        return $task->status === Task::STATUS_SUBMITTED
            && ($task->created_by === $user->id || $user->hasPermissionTo('manage tasks'));
    }
}
