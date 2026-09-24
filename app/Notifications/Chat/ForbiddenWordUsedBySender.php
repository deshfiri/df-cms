<?php

namespace App\Notifications\Chat;

use App\Models\Conversation;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * Tells the sender their own message tripped the forbidden-word list, naming
 * which word it was so they can see exactly what to fix — highlighted in the
 * message itself too (see ChatWordFilter::highlight()). The message still
 * went through (see ChatService::sendMessage()); this is a warning, not an
 * undo.
 */
class ForbiddenWordUsedBySender extends Notification
{
    use BroadcastsToDashboard;

    /** @param  array<int,string>  $matchedWords */
    public function __construct(
        private readonly Conversation $conversation,
        private readonly int $senderId,
        private readonly array $matchedWords,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $words = implode(', ', $this->matchedWords);

        return [
            'title'   => 'Message flagged',
            'message' => "A message you just sent used a restricted word ({$words}). Please keep it professional.",
            // message_html is the same text with the word(s) wrapped for a
            // red highlight — see shell-b.js, which prefers it over
            // 'message' when present. Both are pre-escaped: never raw markup.
            'message_html' => 'A message you just sent used a restricted word ('
                . implode(', ', array_map(fn (string $w) => '<span class="notif-flagged-word">' . e($w) . '</span>', $this->matchedWords))
                . '). Please keep it professional.',
            // The chat *page*, not the AJAX endpoints that feed it (chat.open,
            // chat.groups.show return raw JSON) — the page reads ?user= /
            // ?group= itself and opens the thread; see chat/index.blade.php.
            'url' => $this->conversation->isGroup()
                ? route('chat.index', ['group' => $this->conversation->id])
                : route('chat.index', ['user' => $this->conversation->otherParticipantId($this->senderId)]),
        ];
    }
}
