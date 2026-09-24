<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\Chat\ForbiddenWordDetected;
use App\Notifications\Chat\ForbiddenWordUsedBySender;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Notification;

/**
 * What happens when a chat message trips the forbidden-word list: the
 * message itself already went through (see ChatService::sendMessage()) —
 * this warns whoever sent it and tells whoever manages chat moderation.
 */
class ChatModerationService
{
    public function __construct(
        private readonly ChatWordFilter $filter,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Check a just-sent message against the forbidden-word list and, if it
     * matches, warn the sender and notify moderators. A no-op when nothing
     * matches, so this is safe to call on every message unconditionally.
     */
    public function review(Message $message, Conversation $conversation, User $sender): void
    {
        $matched = $this->filter->match($message->body);

        if (empty($matched)) {
            return;
        }

        $sender->notify(new ForbiddenWordUsedBySender($conversation, $sender->id, $matched));

        // Direct query rather than NotifiesStaff::staffRecipients(): that
        // helper narrows to specific roles first, but 'manage chat
        // moderation' can just as well be granted to one person directly —
        // this needs to reach anyone who holds it, however they got it.
        $moderators = User::query()
            ->permission('manage chat moderation')
            ->where('is_active', true)
            ->whereKeyNot($sender->getKey())
            ->get();

        if ($moderators->isNotEmpty()) {
            Notification::send($moderators, new ForbiddenWordDetected($message, $conversation, $sender, $matched));
        }

        $this->activityLog->log(
            module: 'Chat',
            action: 'Forbidden Word Used',
            clientId: null,
            oldValue: null,
            newValue: ['message_id' => $message->id, 'conversation_id' => $conversation->id, 'words' => $matched],
        );
    }
}
