<?php

namespace App\Events\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A customer message arrived.
 *
 * Broadcast on three channels, each with its own authorization rule, so that no
 * single channel has to decide who may hear about a conversation:
 *
 *  - the thread itself, for anyone with it open;
 *  - the assigned agent's personal channel, for their badge;
 *  - the global inbox channel, which only 'view all whatsapp' can join.
 *
 * Channel names are prefixed `whatsapp.` so they can never collide with the
 * internal chat's `conversation.{id}`.
 */
class WhatsAppMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WhatsAppConversation $conversation,
        public WhatsAppMessage $message,
    ) {
    }

    /** @return array<int,PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('whatsapp.conversation.' . $this->conversation->id),
            new PrivateChannel('whatsapp.inbox'),
        ];

        if ($this->conversation->assigned_user_id) {
            $channels[] = new PrivateChannel('whatsapp.user.' . $this->conversation->assigned_user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'whatsapp.message.received';
    }

    /**
     * Nothing here is a credential, and the media URL is our own proxied route
     * rather than Meta's expiring one.
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'brand_id'        => $this->conversation->brand_id,
            'unread_count'    => $this->conversation->unread_count,
            'message'         => [
                'id'         => $this->message->id,
                'direction'  => $this->message->direction,
                'type'       => $this->message->type,
                'body'       => $this->message->body,
                'preview'    => $this->message->previewLine(),
                'status'     => $this->message->status,
                'created_at' => $this->message->created_at->toIso8601String(),
                'has_media'  => $this->message->hasMedia() || filled($this->message->media_id),
            ],
        ];
    }
}
