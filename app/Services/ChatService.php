<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChatService
{
    public function sendMessage(Conversation $conversation, User $sender, string $body): Message
    {
        $message = DB::transaction(function () use ($conversation, $sender, $body) {
            $message = $conversation->messages()->create([
                'sender_id' => $sender->id,
                'body'      => $body,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        // Broadcast is best-effort and lives outside the transaction: if the
        // Reverb server is unreachable the message is still saved and delivered
        // on next load, we just skip the realtime push.
        $message->setRelation('sender', $sender);
        try {
            broadcast(new MessageSent($message, $conversation->otherParticipantId($sender->id)));
        } catch (\Throwable $e) {
            report($e);
        }

        return $message;
    }

    /** Mark the other participant's messages in a conversation as read for $user. */
    public function markRead(Conversation $conversation, User $user): void
    {
        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Total unread messages addressed to $user across all conversations. */
    public function unreadCountFor(User $user): int
    {
        return Message::whereHas('conversation', fn ($q) => $q->forUser($user->id))
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();
    }
}
