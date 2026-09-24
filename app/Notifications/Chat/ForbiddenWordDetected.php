<?php

namespace App\Notifications\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The summary sent to whoever holds 'manage chat moderation' when a message
 * matches the forbidden-word list. The message itself is not blocked (see
 * ChatService::sendMessage()) — this is what lets someone follow up on it.
 *
 * The link opens the conversation through the chat monitor, which is gated
 * by the separate 'monitor chats' permission — a role granted only
 * 'manage chat moderation' will need that too to open it from here.
 */
class ForbiddenWordDetected extends Notification
{
    use BroadcastsToDashboard;

    /** @param  array<int,string>  $matchedWords */
    public function __construct(
        private readonly Message $message,
        private readonly Conversation $conversation,
        private readonly User $sender,
        private readonly array $matchedWords,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $where = $this->conversation->isGroup()
            ? 'the group "' . $this->conversation->name . '"'
            : 'a direct message';

        return [
            'title'   => 'Restricted word used in chat',
            'message' => "{$this->sender->name} used a restricted word (" . implode(', ', $this->matchedWords) . ") in {$where}: "
                . '"' . Str::limit((string) $this->message->body, 200) . '"',
            // The monitor *page*, not the AJAX endpoint that feeds it — that
            // returns raw JSON and isn't a page a click should land on. The
            // page reads ?conversation= itself and opens it once the list
            // has loaded; see chat/monitor.blade.php.
            'url'     => route('chat.monitor', ['conversation' => $this->conversation->id]),
        ];
    }
}
