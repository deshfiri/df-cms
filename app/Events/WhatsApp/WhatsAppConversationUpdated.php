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
 * Something about a conversation changed that the inbox should reflect:
 * assignment, status, unread count, or a message's delivery state.
 *
 * Deliberately one event rather than four. The inbox reacts to all of them the
 * same way — re-render the row — and a single event keeps the client wiring to
 * one listener instead of a switch.
 */
class WhatsAppConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WhatsAppConversation $conversation,
        /** Present when the trigger was a delivery-status change. */
        public ?WhatsAppMessage $message = null,
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
        return 'whatsapp.conversation.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id'  => $this->conversation->id,
            'brand_id'         => $this->conversation->brand_id,
            'status'           => $this->conversation->status,
            'assigned_user_id' => $this->conversation->assigned_user_id,
            'assignee_name'    => $this->conversation->assignee?->name,
            'unread_count'     => $this->conversation->unread_count,
            'last_preview'     => $this->conversation->last_message_preview,
            'last_message_at'  => $this->conversation->last_message_at?->toIso8601String(),
            'message'          => $this->message ? [
                'id'     => $this->message->id,
                'status' => $this->message->status,
                'wamid'  => $this->message->wamid,
                'error'  => $this->message->error_message,
            ] : null,
        ];
    }
}
