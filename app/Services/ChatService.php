<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatService
{
    /**
     * Container formats MediaRecorder actually produces. WebM and Ogg are the
     * ambiguous ones — an audio-only WebM is routinely sniffed as video/webm,
     * so the guessed mime alone cannot say "this is a voice note".
     */
    private const VOICE_MIMES = [
        'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/aac',
        'audio/wav', 'audio/x-wav', 'video/webm', 'video/ogg', 'application/ogg',
    ];

    /**
     * @param  UploadedFile|null  $file  Optional attachment; an image may be
     *                                   sent with no accompanying text.
     * @param  int|null  $voiceDuration  Seconds, for a recorded voice note. Only
     *                                   honoured when the upload really is audio,
     *                                   so a mislabelled file just renders as a
     *                                   normal file chip instead of a dead player.
     */
    public function sendMessage(
        Conversation $conversation,
        User $sender,
        ?string $body,
        ?UploadedFile $file = null,
        ?int $voiceDuration = null,
    ): Message {
        $attachment = $file ? $this->storeAttachment($conversation, $file) : [];

        if ($attachment && $voiceDuration && in_array($attachment['attachment_mime'], self::VOICE_MIMES, true)) {
            $attachment['attachment_duration'] = $voiceDuration;
        }

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

    /**
     * Retract a message.
     *
     * The row and its content are kept — only the presentation changes for
     * participants. Chat monitors are expected to see what was actually said,
     * which is the whole reason this is a flag rather than a delete.
     */
    public function deleteMessage(Message $message, User $actor): Message
    {
        if ($message->isDeleted()) {
            return $message;
        }

        $message->forceFill([
            'deleted_at' => now(),
            'deleted_by' => $actor->id,
        ])->save();

        $this->broadcastUpdate($message);

        return $message;
    }

    /**
     * Toggle one emoji from one person. Reacting with the same emoji twice
     * removes it, which is what every chat client trains people to expect.
     */
    public function toggleReaction(Message $message, User $user, string $emoji): Message
    {
        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id'    => $user->id,
                'emoji'      => $emoji,
            ]);
        }

        $message->load('reactions');
        $this->broadcastUpdate($message);

        return $message;
    }

    /** Best-effort realtime push; the change is already persisted either way. */
    private function broadcastUpdate(Message $message): void
    {
        // The payload enumerates reactors, so the relation must be present —
        // deleteMessage() has no reason to have loaded it.
        $message->loadMissing('reactions');

        try {
            broadcast(new MessageUpdated($message));
        } catch (\Throwable $e) {
            report($e);
        }
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
