<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskDelegationService;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and act on a task.
 *
 * The rule, in one place:
 *  - "manage tasks" (admins and whoever a role grants it to) sees and manages
 *    every task;
 *  - everyone else with "view tasks" sees only tasks they created or that are
 *    assigned to them — and manages none of them beyond their own work on it
 *    (start/pause, submit, review what they asked for).
 *
 * Every check uses can(), never hasPermissionTo(): the latter throws when a
 * permission row has not been seeded, turning a missing grant into a server
 * error instead of a plain refusal.
 */
class TaskPolicy
{
    public function __construct(
        private readonly TaskDelegationService $delegation,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny(['view tasks', 'manage tasks']);
    }

    /**
     * A task is between the person who asked for it and the person doing it.
     *
     * Mirrors Task::scopeVisibleTo exactly. The two must agree: the scope keeps
     * other people's work out of listings, this keeps it out of direct access,
     * and any divergence between them is a hole — a task hidden from the list
     * but reachable by editing the id in a URL.
     */
    public function view(User $user, Task $task): bool
    {
        if (!$this->viewAny($user)) {
            return false;
        }

        // Oversight, and what lets a manager clear a stalled review queue.
        if ($user->can('manage tasks')) {
            return true;
        }

        return $this->isParty($user, $task);
    }

    /**
     * 'manage tasks' as before, plus stage workers who have somebody to
     * delegate to. What they may put in `assigned_to` is then narrowed to
     * their next stage — see TaskDelegationService.
     */
    public function create(User $user): bool
    {
        return $user->can('manage tasks') || $this->delegation->canDelegate($user);
    }

    /** The full edit — title, brief, deadline, assignee — is management's. */
    public function update(User $user, Task $task): bool
    {
        return $user->can('manage tasks');
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can('manage tasks');
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
        return (int) $task->assigned_to === (int) $user->id
            && in_array($task->status, Task::$workingStatuses, true);
    }

    /**
     * Only the person holding the task hands it back for review, and only while
     * it is still theirs to hand in. Denials carry a reason, which the AJAX
     * response shows instead of a bare "unauthorized".
     *
     * Allowed here does not mean submittable right now: a task not yet started
     * shows its Submit button disabled ("Start work first"), and TaskService
     * refuses it with that reason — see Task::submitBlocker().
     */
    public function submit(User $user, Task $task): Response
    {
        if ((int) $task->assigned_to !== (int) $user->id) {
            return Response::deny('Only the person this task is assigned to can submit it.');
        }

        if (in_array($task->status, Task::$settledStatuses, true)) {
            return Response::deny($task->submitBlocker());
        }

        return Response::allow();
    }

    /**
     * Whoever asked for the work decides whether it is done. Someone with
     * 'manage tasks' can also clear a review so nothing gets stuck behind a
     * person who has left or is away.
     */
    public function review(User $user, Task $task): bool
    {
        return $task->status === Task::STATUS_SUBMITTED
            && ((int) $task->created_by === (int) $user->id || $user->can('manage tasks'));
    }

    /** Created it, or holds it. */
    private function isParty(User $user, Task $task): bool
    {
        return (int) $task->assigned_to === (int) $user->id
            || (int) $task->created_by === (int) $user->id;
    }
}
