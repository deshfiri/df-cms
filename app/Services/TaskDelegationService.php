<?php

namespace App\Services;

use App\Models\FlowStage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a person may hand work to.
 *
 * Someone working a workflow stage can delegate a task to the people on the
 * stage immediately after theirs — the next pair of hands the work would reach
 * anyway. It gives stage workers a way to ask for something specific without
 * granting them the run of the task module.
 *
 * Anyone who already holds 'manage tasks' is unrestricted; this only ever adds
 * a capability, it never takes assignment choices away from people who had them.
 */
class TaskDelegationService
{
    /**
     * User ids $actor may assign a task to, or null when unrestricted.
     *
     * @return Collection<int,int>|null
     */
    public function assignableUserIds(User $actor): ?Collection
    {
        if ($actor->can('manage tasks')) {
            return null;
        }

        return $this->nextStageUserIds($actor);
    }

    /** Can this person delegate at all? */
    public function canDelegate(User $actor): bool
    {
        return $this->nextStageUserIds($actor)->isNotEmpty();
    }

    public function mayAssignTo(User $actor, ?int $assigneeId): bool
    {
        if ($assigneeId === null) {
            return true;   // unassigned is always allowed
        }

        $allowed = $this->assignableUserIds($actor);

        return $allowed === null || $allowed->contains($assigneeId);
    }

    /**
     * Active members of the stage directly after each stage $actor works.
     *
     * A person can sit on stages in several flows, so this is the union of the
     * next stage in each of them.
     *
     * @return Collection<int,int>
     */
    public function nextStageUserIds(User $actor): Collection
    {
        $mine = FlowStage::whereHas('users', fn ($q) => $q->where('users.id', $actor->id))
            ->get(['id', 'flow_id', 'position']);

        if ($mine->isEmpty()) {
            return collect();
        }

        return FlowStage::query()
            // Guarded by the isEmpty() above: an empty closure here would match
            // every stage in the system rather than none.
            ->where(function ($query) use ($mine) {
                foreach ($mine as $stage) {
                    $query->orWhere(fn ($q) => $q
                        ->where('flow_id', $stage->flow_id)
                        ->where('position', $stage->position + 1));
                }
            })
            ->with(['users' => fn ($q) => $q->where('users.is_active', true)])
            ->get()
            ->flatMap(fn (FlowStage $stage) => $stage->users->pluck('id'))
            ->unique()
            ->values();
    }
}
