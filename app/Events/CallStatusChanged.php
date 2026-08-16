<?php

namespace App\Events;

use App\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Every lifecycle transition after ringing — accepted, rejected, busy, missed,
 * cancelled, ended, failed — delivered to one specific participant.
 *
 * One event rather than six near-identical classes: the payload is the same
 * shape in each case and the client switches on `status`. ShouldBroadcastNow
 * for the same latency reason as CallRinging; "they hung up" arriving late is
 * how you get two people talking to a dead line.
 */
class CallStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int  $recipientId  The participant being told.
     */
    public function __construct(
        public Call $call,
        public int $recipientId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'call.status';
    }

    public function broadcastWith(): array
    {
        return [
            'call_uuid'        => $this->call->uuid,
            'status'           => $this->call->status,
            'caller_id'        => $this->call->caller_id,
            'callee_id'        => $this->call->callee_id,
            'ended_by'         => $this->call->ended_by,
            'duration_seconds' => $this->call->duration_seconds,
            'failure_reason'   => $this->call->failure_reason,
        ];
    }
}
