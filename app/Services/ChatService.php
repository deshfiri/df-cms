<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatService
{
    /**
     * @param  UploadedFile|null  $file  Optional attachment; an image may be
     *                                   sent with no accompanying text.
     */
    public function sendMessage(Conversation $conversation, User $sender, ?string $body, ?UploadedFile $file = null): Message
    {
        $attachment = $file ? $this->storeAttachment($conversation, $file) : [];

        $message = DB::transaction(function () use ($conversation, $sender, $body, $attachment) {
            $message = $conversation->messages()->create([
                'sender_id' => $sender->id,
                'body'      => $body !== null && $body !== '' ? $body : null,
            ] + $attachment);

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

    /**
     * Store on the private disk, under the conversation, with a generated name.
     *
     * The original filename is kept only as a label — it never touches the
     * filesystem, so a crafted name cannot traverse directories or collide with
     * another upload.
     *
     * @return array<string,mixed>
     */
    private function storeAttachment(Conversation $conversation, UploadedFile $file): array
    {
        $stored = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension() ?: 'bin');

        return [
            'attachment_path' => $file->storeAs('chat/' . $conversation->id, $stored, 'local'),
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_mime' => $file->getMimeType() ?: 'application/octet-stream',
            'attachment_size' => $file->getSize(),
        ];
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
