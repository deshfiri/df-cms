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
 *  - "manage all tasks" (admins and whoever is granted it) sees and manages
 *    every task — oversight;
 *  - everyone else sees only tasks they created or that are assigned to them.
 *    With "manage tasks" they may also create tasks, and edit or delete the
 *    ones they created; the assignee's part is their own work on it
 *    (start/pause, submit), and the creator reviews what they asked for.
 *
 * "manage tasks" used to mean oversight too, which opened every task to the
 * Sales and Support teams that hold it to hand out work.
 *
 * Every check uses can(), never hasPermissionTo(): the latter throws when a
 * permission row has not been seeded, turning a missing grant into a server
 * error instead of a plain refusal.
 */
class TaskPolicy
{
    public const OVERSIGHT = 'manage all tasks';

    public function __construct(
        private readonly TaskDelegationService $delegation,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny(['view tasks', 'manage tasks', self::OVERSIGHT]);
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
        if ($user->can(self::OVERSIGHT)) {
            return true;
        }

        return $this->isParty($user, $task);
    }

    /**
     * 'manage tasks' (or oversight), plus stage workers who have somebody to
     * delegate to. What they may put in `assigned_to` is then narrowed to
     * their next stage — see TaskDelegationService.
     */
    public function create(User $user): bool
    {
        return $user->canAny(['manage tasks', self::OVERSIGHT]) || $this->delegation->canDelegate($user);
    }

    /**
     * The full edit — title, brief, deadline, assignee: oversight on any task,
     * or 'manage tasks' on a task you created. Being assigned a task is not a
     * licence to rewrite it.
     */
    public function update(User $user, Task $task): bool
    {
        return $user->can(self::OVERSIGHT)
            || ($user->can('manage tasks') && $this->created($user, $task));
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /** Removing someone else's comment or file on a task: oversight only. */
    public function moderate(User $user, Task $task): bool
    {
        return $user->can(self::OVERSIGHT);
    }

    /**
     * The assignee moves their own task between the working statuses.
     *
     * Separate from update(), which is the full edit.
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
        return $task->assignees->contains($user->id)
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
        if (!$task->assignees->contains($user->id)) {
            return Response::deny('Only someone this task is assigned to can submit it.');
        }

        if (in_array($task->status, Task::$settledStatuses, true)) {
            return Response::deny($task->submitBlocker());
        }

        return Response::allow();
    }

    /**
     * Whoever asked for the work decides whether it is done. Oversight can
     * also clear a review so nothing gets stuck behind a person who has left
     * or is away.
     */
    public function review(User $user, Task $task): bool
    {
        return $task->status === Task::STATUS_SUBMITTED
            && ($this->created($user, $task) || $user->can(self::OVERSIGHT));
    }

    /**
     * Sending work back for rework: whoever asked for it always could, via
     * update() — reopening a finished task is a management call. An assignee
     * can send it back too, at any working stage — before they've even
     * started, mid-work, or after handing it in — not only once it has been
     * submitted, since a flawed brief can surface at any point. Either way
     * it is recorded the same way, on the same quality KPI — see
     * TaskService::requestRevision() and PerformanceCalculationService::revisionRate().
     */
    public function requestRevision(User $user, Task $task): bool
    {
        if ($this->update($user, $task)) {
            return true;
        }

        // An assignee may send it back at any working stage — before they've
        // even started, mid-work, or after handing it in — not only once it's
        // been submitted. A flaw in the brief can surface at any point.
        return $task->assignees->contains($user->id)
            && in_array($task->status, [...Task::$workingStatuses, Task::STATUS_SUBMITTED, 'Completed'], true);
    }

    /** Created it, or holds it. */
    private function isParty(User $user, Task $task): bool
    {
        return $task->assignees->contains($user->id) || $this->created($user, $task);
    }

    private function created(User $user, Task $task): bool
    {
        return (int) $task->created_by === (int) $user->id;
    }
}
