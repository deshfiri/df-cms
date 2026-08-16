<?php

namespace App\Events;

use App\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Relays one WebRTC negotiation payload — SDP offer, SDP answer, or an ICE
 * candidate — to the other participant.
 *
 * The server is a dumb pipe here: it never parses or stores SDP/ICE, it only
 * checks that the sender is a participant of a live call and forwards the blob
 * to the other one. Nothing about the media path touches Laravel.
 *
 * ShouldBroadcastNow is mandatory rather than merely preferable. ICE candidates
 * are useless if they arrive after negotiation has moved on, and a queue would
 * also reorder them relative to the offer/answer they belong to.
 */
class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const TYPE_OFFER  = 'offer';
    public const TYPE_ANSWER = 'answer';
    public const TYPE_ICE    = 'ice';

    public function __construct(
        public Call $call,
        public int $recipientId,
        public string $type,
        public array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'call.signal';
    }

    public function broadcastWith(): array
    {
        return [
            // The uuid lets the client drop anything belonging to a different
            // or already-finished call rather than feeding it to the wrong
            // RTCPeerConnection.
            'call_uuid' => $this->call->uuid,
            'type'      => $this->type,
            'payload'   => $this->payload,
        ];
    }
}
