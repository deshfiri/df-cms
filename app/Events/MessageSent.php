<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a new chat message on the conversation channel (both participants +
 * any monitor listen there) and on the recipient's personal channel (for the
 * nav unread badge when they're not viewing the thread). ShouldBroadcastNow so
 * delivery is immediate and doesn't depend on a queue worker.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public int $recipientId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
            new PrivateChannel('App.Models.User.' . $this->recipientId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id'       => $this->message->sender_id,
            'sender_name'     => $this->message->sender->name,
            'body'            => $this->message->body,
            'created_at'      => $this->message->created_at->toIso8601String(),
            // Carried on the event so an attachment renders the moment it
            // arrives, rather than only after the thread is reloaded.
            'attachment'      => $this->message->hasAttachment() ? [
                'name'     => $this->message->attachment_name,
                'size'     => $this->message->attachmentSizeForHumans(),
                'is_image' => $this->message->attachmentIsImage(),
                'url'      => route('chat.attachment', $this->message),
            ] : null,
        ];
    }
}
