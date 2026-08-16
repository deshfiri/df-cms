<?php

namespace App\Events;

use App\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the callee their phone is ringing.
 *
 * ShouldBroadcastNow, not queued: everything about a call is latency-critical.
 * A queued broadcast waits on a worker, and a worker that is busy exporting a
 * spreadsheet would make the callee's phone ring seconds late — or after the
 * caller has already given up. The same reasoning already applies to
 * MessageSent. Reverb delivery is a local HTTP call to 127.0.0.1, so the cost
 * to the request is small and bounded.
 */
class CallRinging implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Call $call) {}

    public function broadcastOn(): array
    {
        // Only the callee. Call signalling is never broadcast to a shared channel.
        return [new PrivateChannel('App.Models.User.' . $this->call->callee_id)];
    }

    public function broadcastAs(): string
    {
        return 'call.ringing';
    }

    public function broadcastWith(): array
    {
        return [
            'call_uuid'       => $this->call->uuid,
            'caller_id'       => $this->call->caller_id,
            'callee_id'       => $this->call->callee_id,
            'caller_name'     => $this->call->caller->name ?? 'Unknown',
            'conversation_id' => $this->call->conversation_id,
            'started_at'      => $this->call->started_at?->toIso8601String(),
            'ring_timeout'    => (int) config('webrtc.ring_timeout'),
        ];
    }
}
