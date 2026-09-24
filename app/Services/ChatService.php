<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Services\Chat\ChatModerationService;
use App\Services\Storage\StorageSettings;
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

    public function __construct(
        private readonly StorageSettings $storage,
        private readonly ChatModerationService $moderation,
    ) {}

    /**
     * @param  UploadedFile|null  $file  Optional attachment; an image may be
     *                                   sent with no accompanying text.
     * @param  int|null  $voiceDuration  Seconds, for a recorded voice note. Only
     *                                   honoured when the upload really is audio,
     *                                   so a mislabelled file just renders as a
     *                                   normal file chip instead of a dead player.
     * @param  Message|null  $replyTo  The message being quoted. Silently ignored
     *                                 unless it belongs to this same conversation
     *                                 — quoting across threads would expose one
     *                                 conversation's text inside another.
     */
    public function sendMessage(
        Conversation $conversation,
        User $sender,
        ?string $body,
        ?UploadedFile $file = null,
        ?int $voiceDuration = null,
        ?Message $replyTo = null,
    ): Message {
        $attachment = $file ? $this->storeAttachment($conversation, $file) : [];

        if ($attachment && $voiceDuration && in_array($attachment['attachment_mime'], self::VOICE_MIMES, true)) {
            $attachment['attachment_duration'] = $voiceDuration;
        }

        $replyToId = ($replyTo && $replyTo->conversation_id === $conversation->id) ? $replyTo->id : null;

        $message = DB::transaction(function () use ($conversation, $sender, $body, $attachment, $replyToId) {
            $message = $conversation->messages()->create([
                'sender_id'   => $sender->id,
                'reply_to_id' => $replyToId,
                'body'        => $body !== null && $body !== '' ? $body : null,
            ] + $attachment);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        // Broadcast is best-effort and lives outside the transaction: if the
        // Reverb server is unreachable the message is still saved and delivered
        // on next load, we just skip the realtime push.
        $message->setRelation('sender', $sender);
        $message->setRelation('conversation', $conversation);
        // The quote travels with the event so it renders on arrival rather than
        // only after the recipient reloads the thread.
        $message->loadMissing('replyTo.sender:id,name');
        try {
            broadcast(new MessageSent($message, $conversation->recipientIdsFor($sender->id)));
        } catch (\Throwable $e) {
            report($e);
        }

        // Sending is reading: your own message must never count as unread for you.
        if ($conversation->isGroup()) {
            $this->markRead($conversation, $sender);
        }

        // Best-effort, same as the broadcast above: the message has already
        // been sent and must stay sent regardless of what happens here.
        try {
            $this->moderation->review($message, $conversation, $sender);
        } catch (\Throwable $e) {
            report($e);
        }

        return $message;
    }

    // ── Groups ───────────────────────────────────────────────────────────

    /**
     * Start a group. The creator owns it; everyone chosen joins as a member.
     *
     * @param  array<int,int>  $memberIds
     */
    public function createGroup(User $creator, string $name, array $memberIds): Conversation
    {
        return DB::transaction(function () use ($creator, $name, $memberIds) {
            $group = Conversation::create([
                'type'       => Conversation::TYPE_GROUP,
                'name'       => $name,
                'created_by' => $creator->id,
            ]);

            $group->members()->attach($creator->id, ['role' => Conversation::ROLE_OWNER]);
            $this->attachMembers($group, $memberIds);

            return $group->load('members');
        });
    }

    /** @param  array<int,int>  $userIds */
    public function addMembers(Conversation $group, array $userIds): Conversation
    {
        $this->attachMembers($group, $userIds);

        return $group->load('members');
    }

    public function removeMember(Conversation $group, User $member): Conversation
    {
        $group->members()->detach($member->id);

        return $group->load('members');
    }

    public function renameGroup(Conversation $group, string $name): Conversation
    {
        $group->update(['name' => $name]);

        return $group;
    }

    /**
     * Leave a group. An owner walking out hands the group to whoever has been
     * in it longest, so a group is never left with nobody able to manage it.
     */
    public function leaveGroup(Conversation $group, User $user): void
    {
        DB::transaction(function () use ($group, $user) {
            $wasOwner = $group->roleOf($user) === Conversation::ROLE_OWNER;

            $group->members()->detach($user->id);

            if ($wasOwner) {
                $heir = DB::table('conversation_user')
                    ->where('conversation_id', $group->id)
                    ->orderByRaw("CASE role WHEN 'admin' THEN 0 ELSE 1 END")
                    ->orderBy('id')
                    ->value('user_id');

                if ($heir) {
                    $group->members()->updateExistingPivot($heir, ['role' => Conversation::ROLE_OWNER]);
                }
            }
        });
    }

    /**
     * New members start "caught up": everything already said is history, not a
     * flood of unread messages the moment they are added.
     *
     * @param  array<int,int>  $userIds
     */
    private function attachMembers(Conversation $group, array $userIds): void
    {
        $existing = $group->members()->pluck('users.id')->all();
        $caughtUp = $group->messages()->max('id');

        $new = User::whereIn('id', $userIds)
            ->where('is_active', true)
            ->whereNotIn('id', $existing)
            ->pluck('id');

        foreach ($new as $id) {
            $group->members()->attach($id, [
                'role'                 => Conversation::ROLE_MEMBER,
                'last_read_message_id' => $caughtUp,
            ]);
        }
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

        $disk = $this->storage->activeDisk();

        return [
            'attachment_path' => $file->storeAs('chat/' . $conversation->id, $stored, $disk),
            'attachment_disk' => $disk,
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

    /**
     * Mark a conversation read for $user.
     *
     * Direct: stamp the other person's messages, which is what drives read
     * receipts. Group: move this member's own marker to the newest message —
     * stamping the messages would mark them read for every member at once.
     */
    public function markRead(Conversation $conversation, User $user): void
    {
        if ($conversation->isGroup()) {
            $latest = $conversation->messages()->max('id');

            if ($latest) {
                $conversation->members()->updateExistingPivot($user->id, ['last_read_message_id' => $latest]);
            }

            return;
        }

        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Total unread messages addressed to $user across all conversations. */
    public function unreadCountFor(User $user): int
    {
        $direct = Message::whereHas('conversation', fn ($q) => $q
                ->where('type', Conversation::TYPE_DIRECT)
                ->where(fn ($p) => $p->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id)))
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();

        return $direct + array_sum($this->groupUnread($user));
    }

    /**
     * Unread count per group for one member: others' messages past their marker.
     *
     * @param  array<int,int>|null  $conversationIds  limit to these groups
     * @return array<int,int>  conversation id => unread
     */
    public function groupUnread(User $user, ?array $conversationIds = null): array
    {
        return DB::table('messages')
            ->join('conversation_user as cu', function ($join) use ($user) {
                $join->on('cu.conversation_id', '=', 'messages.conversation_id')
                    ->where('cu.user_id', '=', $user->id);
            })
            ->when($conversationIds !== null, fn ($q) => $q->whereIn('messages.conversation_id', $conversationIds ?: [0]))
            ->where('messages.sender_id', '!=', $user->id)
            ->whereRaw('messages.id > COALESCE(cu.last_read_message_id, 0)')
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id, COUNT(*) as unread')
            ->pluck('unread', 'messages.conversation_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
