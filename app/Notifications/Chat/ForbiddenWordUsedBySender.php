<?php

namespace App\Notifications\Chat;

use App\Models\Conversation;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * Tells the sender their own message tripped the forbidden-word list.
 *
 * Deliberately doesn't name the word that matched — the point is to remind
 * people of the chat guidelines, not to hand out the exact list to test
 * around. The message still went through (see ChatService::sendMessage());
 * this is a warning, not an undo.
 */
class ForbiddenWordUsedBySender extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(private readonly Conversation $conversation, private readonly int $senderId) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => 'Message flagged',
            'message' => 'A message you just sent contains language against our chat guidelines. Please keep it professional.',
            'url'     => $this->conversation->isGroup()
                ? route('chat.groups.show', $this->conversation)
                : route('chat.open', $this->conversation->otherParticipantId($this->senderId)),
        ];
    }
}
