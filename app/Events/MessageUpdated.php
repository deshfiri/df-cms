<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message changed after it was sent — deleted, or reacted to.
 *
 * Broadcast on the conversation channel rather than a personal one: both
 * participants (and any monitor watching the thread) need to see a message
 * turn into "deleted" or gain a reaction. ShouldBroadcastNow for the same
 * reason as MessageSent — a delete that arrives late has already failed at its
 * job.
 *
 * Note the payload carries the participant view: the redacted body. Monitors
 * reload the thread to see the original, which keeps the privileged content
 * off a channel that ordinary participants also subscribe to.
 */
class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.' . $this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id'      => $this->message->id,
            'deleted' => $this->message->isDeleted(),
            // Who reacted, not whether "you" did: one payload goes to both
            // participants, so "mine" cannot be resolved server-side. Sending
            // it would blank out the reactor's own highlight the moment their
            // change echoed back to them.
            'reactions' => $this->message->reactions
                ->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'users' => $group->pluck('user_id')->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
