<?php

namespace App\Services;

use App\Exceptions\FlowException;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowStage;
use App\Models\FlowTransition;
use App\Models\User;
use App\Notifications\FlowItemAwaitingYou;
use App\Notifications\FlowItemCompleted;
use App\Notifications\FlowItemNewComment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The generic workflow engine. Items enter a Flow at its first stage and move
 * strictly forward one stage at a time — no skipping — and only a user assigned
 * to an item's current stage may forward it. Every move is recorded as a
 * FlowTransition. Reusable across any number of Flows.
 */
class FlowService
{
    /** Start a new work item into a flow at its first stage. */
    public function createItem(Flow $flow, array $data, User $creator): FlowItem
    {
        $first = $flow->firstStage();
        if (!$first) {
            throw new FlowException('This workflow has no stages yet.');
        }
        if (!$flow->is_active) {
            throw new FlowException('This workflow is inactive.');
        }

        $item = DB::transaction(function () use ($flow, $first, $data, $creator) {
            $item = FlowItem::create([
                'flow_id'          => $flow->id,
                'current_stage_id' => $first->id,
                'title'            => $data['title'],
                'description'      => $data['description'] ?? null,
                'priority'         => $data['priority'] ?? 'Normal',
                'due_date'         => $data['due_date'] ?? null,
                'status'           => FlowItem::STATUS_OPEN,
                'created_by'       => $creator->id,
            ]);

            $this->logTransition($item, null, $first, $creator, $data['note'] ?? null);

            return $item;
        });

        $this->notifyStage($item, $first, $creator, 'New item');

        return $item;
    }

    /**
     * Move an item from its current stage to the next one (or complete it if the
     * current stage is the last). Enforces assignment + no-skip.
     */
    public function advance(FlowItem $item, User $user, ?string $note = null): FlowItem
    {
        if ($item->status !== FlowItem::STATUS_OPEN) {
            throw new FlowException('This item is not open.');
        }

        $current = $item->currentStage;
        if (!$current) {
            throw new FlowException('This item has no current stage.');
        }

        if (!$this->canAct($user, $item)) {
            throw new FlowException($item->assigned_to === null
                ? 'Claim this item before you can send it forward.'
                : 'This item is being handled by someone else.');
        }

        $next = DB::transaction(function () use ($item, $current, $user, $note) {
            $next = $current->nextStage();

            if ($next) {
                // Reset ownership — the next stage's team must claim it.
                $item->update(['current_stage_id' => $next->id, 'assigned_to' => null]);
            } else {
                $item->update([
                    'current_stage_id' => null,
                    'assigned_to'      => null,
                    'status'           => FlowItem::STATUS_COMPLETED,
                    'completed_at'     => now(),
                ]);
            }

            $this->logTransition($item, $current, $next, $user, $note);

            return $next;
        });

        if ($next) {
            $this->notifyStage($item->refresh(), $next, $user, 'Moved forward');
        } else {
            $this->notifyCreatorCompleted($item, $user);
        }

        return $item->fresh(['currentStage']);
    }

    /**
     * Send an item back to the previous stage with a required reason. Only the
     * current-stage assignee (or an admin) may do it; the previous stage's users
     * are notified they have rework.
     */
    public function sendBack(FlowItem $item, User $user, string $reason): FlowItem
    {
        if ($item->status !== FlowItem::STATUS_OPEN) {
            throw new FlowException('This item is not open.');
        }

        $current = $item->currentStage;
        if (!$current) {
            throw new FlowException('This item has no current stage.');
        }

        if (!$this->canAct($user, $item)) {
            throw new FlowException($item->assigned_to === null
                ? 'Claim this item before you can send it back.'
                : 'This item is being handled by someone else.');
        }

        $previous = $this->previousStage($current);
        if (!$previous) {
            throw new FlowException('This is the first stage — there is nowhere to send it back to.');
        }

        DB::transaction(function () use ($item, $current, $previous, $user, $reason) {
            $item->update(['current_stage_id' => $previous->id, 'assigned_to' => null]);
            $this->logTransition($item, $current, $previous, $user, $reason);
        });

        $this->notifyStage($item->refresh(), $previous, $user, 'Sent back for changes');

        return $item->fresh(['currentStage']);
    }

    /** Open items sitting at a stage the user is assigned to — their queue. */
    public function myQueue(User $user): Collection
    {
        $stageIds = $this->assignedStageIds($user);
        if ($stageIds->isEmpty()) {
            return collect();
        }

        $weight = ['Urgent' => 0, 'High' => 1, 'Normal' => 2, 'Low' => 3];

        return FlowItem::with(['flow:id,name', 'currentStage:id,name,position', 'assignee:id,name'])
            ->where('status', FlowItem::STATUS_OPEN)
            ->whereIn('current_stage_id', $stageIds)
            // Unclaimed (available to me) or already claimed by me — never items
            // someone else is handling.
            ->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', $user->id))
            ->get()
            // Urgency order (DB-portable, sorted in PHP): overdue first, then
            // priority, then soonest due date, then oldest.
            ->sortBy(fn (FlowItem $i) => [
                $i->isOverdue() ? 0 : 1,
                $weight[$i->priority] ?? 2,
                $i->due_date ? $i->due_date->timestamp : PHP_INT_MAX,
                $i->id,
            ])
            ->values();
    }

    /** Cancel (withdraw) an open item — creator or workflow admin only. */
    public function cancelItem(FlowItem $item, User $user, ?string $reason = null): FlowItem
    {
        if ($item->status !== FlowItem::STATUS_OPEN) {
            throw new FlowException('Only an open item can be cancelled.');
        }
        if (!$this->canManageItem($user, $item)) {
            throw new FlowException('Only the item creator or a workflow admin can cancel it.');
        }

        return DB::transaction(function () use ($item, $user, $reason) {
            $from = $item->currentStage;
            $item->update(['status' => FlowItem::STATUS_CANCELLED, 'current_stage_id' => null]);
            $this->logTransition($item, $from, null, $user, $reason ? "Cancelled: {$reason}" : 'Cancelled');

            return $item->fresh();
        });
    }

    /** Creator of the item, or a workflow admin — may edit/cancel it. */
    public function canManageItem(User $user, FlowItem $item): bool
    {
        return $item->created_by === $user->id || $user->can('manage workflows');
    }

    /** Notify participants (current-stage assignees + creator, minus the author) of a new comment. */
    public function notifyNewComment(FlowItem $item, User $author, string $body): void
    {
        $recipients = collect();
        if ($item->currentStage) {
            $recipients = $recipients->merge($item->currentStage->users()->where('users.is_active', true)->get());
        }
        if ($item->creator && $item->creator->is_active) {
            $recipients->push($item->creator);
        }

        $recipients = $recipients->unique('id')->reject(fn (User $u) => $u->id === $author->id);

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new FlowItemNewComment($item, $author, $body));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function myQueueCount(User $user): int
    {
        return $this->navSummary($user)['count'];
    }

    /**
     * One-shot data for the sidebar (rendered on every page): is the user a
     * flow participant, and their pending count — sharing a single lookup of
     * their assigned stages instead of querying it twice.
     */
    public function navSummary(User $user): array
    {
        $stageIds = $this->assignedStageIds($user);
        if ($stageIds->isEmpty()) {
            return ['participant' => false, 'count' => 0];
        }

        $count = FlowItem::where('status', FlowItem::STATUS_OPEN)
            ->whereIn('current_stage_id', $stageIds)
            ->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', $user->id))
            ->count();

        return ['participant' => true, 'count' => $count];
    }

    /** True if the user is assigned to the item's current stage (may act on it). */
    public function canAct(User $user, FlowItem $item): bool
    {
        if (!$item->isOpen() || $item->current_stage_id === null) {
            return false;
        }

        // Admins can act on any open item (safety valve). Everyone else must be
        // the user who claimed it.
        return $user->can('manage workflows') || $item->assigned_to === $user->id;
    }

    /** Whether the user may claim an unclaimed item at its current stage. */
    public function canClaim(User $user, FlowItem $item): bool
    {
        if (!$item->isOpen() || $item->current_stage_id === null || $item->assigned_to !== null) {
            return false;
        }

        return $user->can('manage workflows') || $this->assignedStageIds($user)->contains($item->current_stage_id);
    }

    /** Take ownership of an unclaimed item at its current stage. */
    public function claim(FlowItem $item, User $user): FlowItem
    {
        if (!$item->isOpen() || $item->current_stage_id === null) {
            throw new FlowException('This item is not open.');
        }
        if ($item->assigned_to !== null) {
            throw new FlowException($item->assigned_to === $user->id
                ? 'You have already claimed this item.'
                : 'This item has already been claimed by someone else.');
        }
        if (!$this->canClaim($user, $item)) {
            throw new FlowException('Only a user assigned to this stage can claim it.');
        }

        $item->update(['assigned_to' => $user->id]);

        return $item->fresh(['assignee']);
    }

    /** Return a claimed item to the pool — the claimer or an admin. */
    public function release(FlowItem $item, User $user): FlowItem
    {
        if ($item->assigned_to === null) {
            return $item;
        }
        if ($item->assigned_to !== $user->id && !$user->can('manage workflows')) {
            throw new FlowException('Only the person who claimed it (or an admin) can release it.');
        }

        $item->update(['assigned_to' => null]);

        return $item->fresh();
    }

    /** True if the item's current stage has no assignees — it can't move without an admin. */
    public function isStranded(FlowItem $item): bool
    {
        return $item->isOpen()
            && $item->current_stage_id !== null
            && !DB::table('flow_stage_user')->where('flow_stage_id', $item->current_stage_id)->exists();
    }

    /**
     * Whether the user may add/remove attachments: the item is open and they
     * are working its current stage (or a workflow admin).
     */
    public function canAttach(User $user, FlowItem $item): bool
    {
        return $item->isOpen() && ($this->canAct($user, $item) || $user->can('manage workflows'));
    }

    /** Whether the user is allowed to see an item at all (assignee anywhere on the flow, creator, or admin). */
    public function canView(User $user, FlowItem $item): bool
    {
        if ($user->can('manage workflows') || $item->created_by === $user->id) {
            return true;
        }

        return FlowStage::where('flow_id', $item->flow_id)
            ->whereHas('users', fn ($q) => $q->whereKey($user->id))
            ->exists();
    }

    /** Stage ids of the given flow the user is assigned to (used for start-item gating). */
    public function userAssignedStageIdsForFlow(User $user, Flow $flow): Collection
    {
        return FlowStage::where('flow_id', $flow->id)
            ->whereHas('users', fn ($q) => $q->whereKey($user->id))
            ->pluck('id');
    }

    private function assignedStageIds(User $user): Collection
    {
        return DB::table('flow_stage_user')->where('user_id', $user->id)->pluck('flow_stage_id');
    }

    private function userAssignedToStage(User $user, FlowStage $stage): bool
    {
        return $stage->users()->whereKey($user->id)->exists();
    }

    private function previousStage(FlowStage $stage): ?FlowStage
    {
        return FlowStage::where('flow_id', $stage->flow_id)
            ->where('position', '<', $stage->position)
            ->orderByDesc('position')
            ->first();
    }

    /** Tell the creator their item finished — unless they completed it themselves. */
    private function notifyCreatorCompleted(FlowItem $item, User $actor): void
    {
        $creator = $item->creator;
        if (!$creator || !$creator->is_active || $creator->id === $actor->id) {
            return;
        }

        try {
            $creator->notify(new FlowItemCompleted($item));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Notify a stage's active assignees (except the actor) that an item now needs them. */
    private function notifyStage(FlowItem $item, FlowStage $stage, ?User $except, string $reason): void
    {
        $recipients = $stage->users()
            ->where('users.is_active', true)
            ->when($except, fn ($q) => $q->where('users.id', '!=', $except->id))
            ->get();

        // Per-recipient so a broadcast failure (e.g. Reverb down) for one person
        // never blocks the others — the DB channel runs first and still persists.
        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new FlowItemAwaitingYou($item, $stage, $reason));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function logTransition(FlowItem $item, ?FlowStage $from, ?FlowStage $to, User $actor, ?string $note): void
    {
        FlowTransition::create([
            'flow_item_id' => $item->id,
            'from_stage_id' => $from?->id,
            'to_stage_id'   => $to?->id,
            'moved_by'      => $actor->id,
            'note'          => $note,
        ]);
    }
}
