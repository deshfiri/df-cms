<?php

namespace App\Events;

use App\Models\FlowItem;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone claimed a workflow item.
 *
 * Anyone else with the item open is told who took it — and a stage worker is
 * sent back to their queue, since the item is no longer theirs to pick up.
 * Broadcast now, not queued: an alert that arrives after the other person has
 * started typing a comment has already failed at its job.
 */
class FlowItemClaimed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FlowItem $item,
        public User $claimedBy,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('flow-item.' . $this->item->id)];
    }

    public function broadcastAs(): string
    {
        return 'item.claimed';
    }

    public function broadcastWith(): array
    {
        return [
            'item_id'    => $this->item->id,
            'title'      => $this->item->title,
            'claimed_by' => ['id' => $this->claimedBy->id, 'name' => $this->claimedBy->name],
            'claimed_at' => now()->toIso8601String(),
            'queue_url'  => route('flow.queue'),
        ];
    }
}
