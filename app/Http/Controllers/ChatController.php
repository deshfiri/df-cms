<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatWordFilter;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\MessageReaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatWordFilter $wordFilter,
    ) {
    }

    public function index()
    {
        return view('chat.index');
    }

    /**
     * The current user's conversations, newest first: every 1:1 that has
     * messages, and every group they are in — a new group shows straight away,
     * before anyone has said anything in it.
     */
    public function conversations(): JsonResponse
    {
        $me   = Auth::id();
        $user = Auth::user();

        $conversations = Conversation::forUser($me)
            ->where(fn ($q) => $q->whereNotNull('last_message_at')->orWhere('type', Conversation::TYPE_GROUP))
            ->with([
                'userOne:id,name,avatar,avatar_disk',
                'userTwo:id,name,avatar,avatar_disk',
                'messages' => fn($q) => $q->with('sender:id,name')->latest('id')->limit(1),
            ])
            ->withCount('members')
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->limit(100)
            ->get();

        $directIds = $conversations->reject->isGroup()->pluck('id');
        $groupIds  = $conversations->filter->isGroup()->pluck('id')->all();

        $unread = Message::selectRaw('conversation_id, COUNT(*) as c')
            ->whereIn('conversation_id', $directIds)
            ->where('sender_id', '!=', $me)
            ->whereNull('read_at')
            ->groupBy('conversation_id')
            ->pluck('c', 'conversation_id');

        $groupUnread = $this->chat->groupUnread($user, $groupIds);

        $data = $conversations->map(function (Conversation $c) use ($me, $unread, $groupUnread) {
            $last = $c->messages->first();

            if ($c->isGroup()) {
                return [
                    'conversation_id' => $c->id,
                    'is_group'        => true,
                    'user_id'         => null,
                    'name'            => $c->name,
                    'member_count'    => $c->members_count,
                    'avatar_url'      => null,
                    // In a group the list has to say who spoke.
                    'last_sender'     => $last && $last->sender_id !== $me ? ($last->sender->name ?? null) : null,
                    'last_body'       => $last?->previewLine(),
                    'last_from_me'    => $last && $last->sender_id === $me,
                    'last_at'         => ($c->last_message_at ?? $c->created_at)?->diffForHumans(),
                    'unread'          => (int) ($groupUnread[$c->id] ?? 0),
                ];
            }

            $otherId = $c->otherParticipantId($me);
            $other = (int) $c->user_one_id === $otherId ? $c->userOne : $c->userTwo;

            return [
                'conversation_id' => $c->id,
                'is_group' => false,
                'user_id' => $otherId,
                'name' => $other->name ?? '—',
                'avatar_url' => $other?->avatarUrl(),
                'last_body' => $last?->previewLine(),
                'last_from_me' => $last && $last->sender_id === $me,
                'last_at' => $c->last_message_at?->diffForHumans(),
                'unread' => (int) ($unread[$c->id] ?? 0),
            ];
        });

        return response()->json([
            'conversations' => $data,
            'unread_total'  => $this->chat->unreadCountFor($user),
            'can_create_groups' => $user->can('create chat groups'),
        ]);
    }

    // ── Groups ───────────────────────────────────────────────────────────

    /** Start a group. Only roles granted "create chat groups" may. */
    public function storeGroup(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create chat groups'), 403, 'You are not allowed to create chat groups.');

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:100'],
            'member_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        ], [
            'member_ids.required' => 'Add at least one person to the group.',
            'member_ids.min'      => 'Add at least one person to the group.',
        ]);

        $group = $this->chat->createGroup($request->user(), trim($data['name']), $data['member_ids']);

        return response()->json([
            'success'         => true,
            'conversation_id' => $group->id,
            'group'           => $this->groupResource($group, $request->user()),
        ]);
    }

    /** Open a group: its members, its recent messages, and mark it read. */
    public function showGroup(Conversation $conversation): JsonResponse
    {
        $me = Auth::user();
        $this->mustBeMemberOfGroup($conversation, $me);

        $this->chat->markRead($conversation, $me);

        $messages = $conversation->messages()
            ->with(['sender:id,name', 'reactions', 'replyTo.sender:id,name'])
            ->orderByDesc('id')->limit(200)->get()
            ->sortBy('id')->values()
            ->map(fn(Message $m) => $this->messageResource($m));

        return response()->json([
            'conversation_id' => $conversation->id,
            'group'           => $this->groupResource($conversation, $me),
            'messages'        => $messages,
            'unread_total'    => $this->chat->unreadCountFor($me),
        ]);
    }

    public function sendGroup(Conversation $conversation, Request $request): JsonResponse
    {
        $this->mustBeMemberOfGroup($conversation, Auth::user());

        return $this->deliver($conversation, $request);
    }

    public function updateGroup(Conversation $conversation, Request $request): JsonResponse
    {
        $me = Auth::user();
        $this->mustManageGroup($conversation, $me);

        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $this->chat->renameGroup($conversation, trim($data['name']));

        return response()->json(['success' => true, 'group' => $this->groupResource($conversation, $me)]);
    }

    public function addGroupMembers(Conversation $conversation, Request $request): JsonResponse
    {
        $me = Auth::user();
        $this->mustManageGroup($conversation, $me);

        $data = $request->validate([
            'member_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        $this->chat->addMembers($conversation, $data['member_ids']);

        return response()->json(['success' => true, 'group' => $this->groupResource($conversation, $me)]);
    }

    public function removeGroupMember(Conversation $conversation, User $user): JsonResponse
    {
        $me = Auth::user();
        $this->mustManageGroup($conversation, $me);

        abort_if($user->id === $me->id, 422, 'Use "Leave group" to take yourself out.');
        abort_if($conversation->roleOf($user) === Conversation::ROLE_OWNER, 422, "The group's owner can't be removed.");
        abort_unless($conversation->hasParticipant($user), 404);

        $this->chat->removeMember($conversation, $user);

        return response()->json(['success' => true, 'group' => $this->groupResource($conversation, $me)]);
    }

    public function leaveGroup(Conversation $conversation): JsonResponse
    {
        $me = Auth::user();
        $this->mustBeMemberOfGroup($conversation, $me);

        $this->chat->leaveGroup($conversation, $me);

        return response()->json(['success' => true, 'unread_total' => $this->chat->unreadCountFor($me)]);
    }

    private function mustBeMemberOfGroup(Conversation $conversation, User $user): void
    {
        abort_unless($conversation->isGroup(), 404);
        abort_unless($conversation->hasParticipant($user), 403, 'You are not in this group.');
    }

    private function mustManageGroup(Conversation $conversation, User $user): void
    {
        $this->mustBeMemberOfGroup($conversation, $user);
        abort_unless($conversation->canBeManagedBy($user), 403, 'Only the group owner or an admin can do that.');
    }

    /** @return array<string,mixed> */
    private function groupResource(Conversation $group, User $me): array
    {
        $group->load('members:id,name,avatar,avatar_disk');
        $order = [Conversation::ROLE_OWNER => 0, Conversation::ROLE_ADMIN => 1, Conversation::ROLE_MEMBER => 2];

        return [
            'id'         => $group->id,
            'name'       => $group->name,
            'my_role'    => $group->roleOf($me),
            'can_manage' => $group->canBeManagedBy($me),
            'members'    => $group->members
                ->sortBy(fn (User $u) => [$order[$u->pivot->role] ?? 3, $u->name])
                ->map(fn (User $u) => [
                    'id'         => $u->id,
                    'name'       => $u->name,
                    'avatar_url' => $u->avatarUrl(),
                    'role'       => $u->pivot->role,
                ])->values(),
        ];
    }

    /** People to start a chat with (searchable). */
    public function users(Request $request): JsonResponse
    {
        $q = $request->input('q');

        $users = User::where('is_active', true)
            ->where('id', '!=', Auth::id())
            ->when($q, fn($qq) => $qq->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'avatar', 'avatar_disk'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'avatar_url' => $u->avatarUrl()]);

        return response()->json(['users' => $users]);
    }

    /** Open (find-or-create) the conversation with a user, mark it read, return its messages. */
    public function open(User $user): JsonResponse
    {
        $me = Auth::user();
        abort_if($user->id === $me->id, 422, "You can't chat with yourself.");

        $conversation = Conversation::between($me->id, $user->id);
        $this->chat->markRead($conversation, $me);

        $messages = $conversation->messages()
            ->with(['sender:id,name', 'reactions', 'replyTo.sender:id,name'])
            ->orderByDesc('id')->limit(200)->get()
            ->sortBy('id')->values()
            ->map(fn(Message $m) => $this->messageResource($m));

        return response()->json([
            'conversation_id' => $conversation->id,
            'other' => ['id' => $user->id, 'name' => $user->name, 'avatar_url' => $user->avatarUrl()],
            'messages' => $messages,
            'unread_total' => $this->chat->unreadCountFor($me),
        ]);
    }

    /** Mark the other participant's messages read — called when viewing the thread live. */
    public function read(Conversation $conversation): JsonResponse
    {
        $me = Auth::user();
        abort_unless($conversation->hasParticipant($me->id), 403);

        $this->chat->markRead($conversation, $me);

        return response()->json(['success' => true, 'unread_total' => $this->chat->unreadCountFor($me)]);
    }

    public function send(User $user, Request $request): JsonResponse
    {
        $me = Auth::user();
        abort_if($user->id === $me->id, 422, "You can't chat with yourself.");

        return $this->deliver(Conversation::between($me->id, $user->id), $request);
    }

    /** Validate and send one message into a conversation the sender is already known to be in. */
    private function deliver(Conversation $conversation, Request $request): JsonResponse
    {
        $me = Auth::user();

        // Either half may be omitted, but not both — an empty message is not a
        // message. 20 MB matches the document limit used elsewhere.
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'required_without:body'],
            // Sent only by the recorder. Capped at 10 minutes: a voice note is a
            // message, and anything longer belongs in a call.
            'duration' => ['nullable', 'integer', 'min:1', 'max:600'],
            // The message being quoted. Existence only — that it belongs to this
            // conversation is checked below, where the conversation is known.
            'reply_to_id' => ['nullable', 'integer', 'exists:messages,id'],
        ]);

        $replyTo = isset($data['reply_to_id']) ? Message::find($data['reply_to_id']) : null;
        // Quoting a message from a conversation you are not in would leak its
        // text into this thread, so refuse rather than quietly dropping it.
        abort_if(
            $replyTo && $replyTo->conversation_id !== $conversation->id,
            422,
            'You can only reply to a message in this conversation.',
        );

        $message = $this->chat->sendMessage(
            $conversation,
            $me,
            $data['body'] ?? null,
            $request->file('file'),
            isset($data['duration']) ? (int) $data['duration'] : null,
            $replyTo,
        );

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'message' => $this->messageResource($message),
        ]);
    }

    // ── Monitoring (gated by 'monitor chats') ────────────────────────────

    public function monitor()
    {
        abort_unless(Auth::user()->can('monitor chats'), 403);

        return view('chat.monitor');
    }

    public function monitorConversations(): JsonResponse
    {
        abort_unless(Auth::user()->can('monitor chats'), 403);

        $conversations = Conversation::whereNotNull('last_message_at')
            ->with(['userOne:id,name', 'userTwo:id,name'])
            ->withCount(['messages', 'members'])
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get()
            ->map(fn(Conversation $c) => [
                'id' => $c->id,
                // A group has no pair; the monitor list shows its name and size in the same two slots.
                'user_one' => $c->isGroup() ? '👥 ' . $c->name : ($c->userOne->name ?? '—'),
                'user_two' => $c->isGroup() ? $c->members_count . ' members' : ($c->userTwo->name ?? '—'),
                'messages_count' => $c->messages_count,
                'last_at' => $c->last_message_at?->diffForHumans(),
            ]);

        return response()->json(['conversations' => $conversations]);
    }

    public function monitorShow(Conversation $conversation): JsonResponse
    {
        abort_unless(Auth::user()->can('monitor chats'), 403);

        $conversation->load(['userOne:id,name', 'userTwo:id,name', 'members:id,name']);

        // asMonitor: a retracted message still shows what was actually said,
        // flagged rather than hidden. That is the entire point of monitoring.
        $messages = $conversation->messages()
            ->with(['sender:id,name', 'reactions', 'replyTo.sender:id,name'])
            ->orderByDesc('id')->limit(500)->get()
            ->sortBy('id')->values()
            ->map(fn(Message $m) => $this->messageResource($m, asMonitor: true));

        return response()->json([
            'conversation_id' => $conversation->id,
            'participants' => $conversation->isGroup()
                ? $conversation->members->pluck('name')->all()
                : [$conversation->userOne->name ?? '—', $conversation->userTwo->name ?? '—'],
            'messages' => $messages,
        ]);
    }

    /** Retract one of your own messages. */
    public function destroyMessage(Message $message): JsonResponse
    {
        abort_unless($message->sender_id === Auth::id(), 403, 'You can only delete your own messages.');

        $this->chat->deleteMessage($message, Auth::user());

        return response()->json(['success' => true]);
    }

    /** Toggle one emoji reaction on a message. */
    public function react(Message $message, Request $request): JsonResponse
    {
        $conversation = $message->conversation;
        abort_unless($conversation && $conversation->hasParticipant(Auth::id()), 403);
        abort_if($message->isDeleted(), 422, 'You cannot react to a deleted message.');

        $data = $request->validate([
            'emoji' => ['required', 'string', Rule::in(MessageReaction::ALLOWED)],
        ]);

        $message = $this->chat->toggleReaction($message, Auth::user(), $data['emoji']);

        return response()->json([
            'success'   => true,
            'reactions' => $message->reactionSummary(Auth::id()),
        ]);
    }

    /**
     * Stream a message attachment to a participant.
     *
     * Images are shown inline so a thumbnail can render; everything else is
     * forced as a download, so an uploaded .html or .svg can never execute in
     * the app's own origin.
     */
    public function attachment(Message $message)
    {
        $conversation = $message->conversation;

        $isMonitor = Auth::user()->can('monitor chats');

        abort_unless($isMonitor || ($conversation && $conversation->hasParticipant(Auth::id())), 403);
        abort_unless($message->hasAttachment(), 404);
        // A retracted attachment stays reachable to monitors and nobody else.
        abort_if($message->isDeleted() && !$isMonitor, 404);
        // The disk this attachment was written to, which is not necessarily the
        // one new uploads go to now — see Settings → Storage & CDN.
        $disk = Storage::disk($message->attachment_disk ?: 'local');

        abort_unless($disk->exists($message->attachment_path), 404);

        if ($message->attachmentIsImage()) {
            return $disk->response($message->attachment_path, $message->attachment_name, [
                'Content-Type'            => $message->attachment_mime,
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'",
            ]);
        }

        // A voice note has to be inline for <audio> to play it. Safe for the
        // same reason an image is: the mime was confirmed as audio before the
        // message was ever marked as a recording.
        if ($message->attachmentIsVoice()) {
            return $disk->response($message->attachment_path, $message->attachment_name, [
                'Content-Type'            => $message->attachment_mime,
                'Content-Security-Policy' => "default-src 'none'; media-src 'self'",
            ]);
        }

        return $disk->download($message->attachment_path, $message->attachment_name);
    }

    /**
     * @param  bool  $asMonitor  Monitors see what was actually said, including
     *                           the content of retracted messages. Participants
     *                           get the redacted view.
     */
    private function messageResource(Message $m, bool $asMonitor = false): array
    {
        $redact = $m->isDeleted() && !$asMonitor;

        return [
            'id' => $m->id,
            'sender_id' => $m->sender_id,
            'sender_name' => $m->sender->name ?? '—',
            'body' => $redact ? null : $m->body,
            // Safe HTML with any forbidden word the message tripped wrapped
            // for a red highlight — see ChatWordFilter::highlight().
            'body_html' => $redact ? null : $this->wordFilter->highlight((string) $m->body),
            'created_at' => $m->created_at->toIso8601String(),
            'deleted' => $m->isDeleted(),
            'can_delete' => !$m->isDeleted() && $m->sender_id === Auth::id(),
            'reactions' => $m->relationLoaded('reactions') ? $m->reactionSummary(Auth::id()) : [],
            'reply_to' => $this->replyResource($m),
            // The file is gone, but which file it was is not. Without this the
            // message would render as blank when it had no text of its own.
            'attachment_expired' => !$redact && $m->attachmentWasPurged() ? [
                'name'       => $m->attachment_name,
                'size'       => $m->attachmentSizeForHumans(),
                'purged_at'  => $m->attachment_purged_at->format('d M Y'),
            ] : null,
            'attachment' => (!$redact && $m->hasAttachment()) ? [
                'name'     => $m->attachment_name,
                'size'     => $m->attachmentSizeForHumans(),
                'is_image' => $m->attachmentIsImage(),
                'is_voice' => $m->attachmentIsVoice(),
                'duration' => $m->attachmentIsVoice() ? $m->formattedAttachmentDuration() : null,
                'url'      => route('chat.attachment', $m),
            ] : null,
        ];
    }

    /**
     * The quoted message shown above a reply.
     *
     * Only ever a snippet: the id to jump to, who wrote it, and one line of
     * what it said. A retracted message keeps its place in the quote — hiding
     * it would leave a reply answering nothing.
     *
     * @return array<string,mixed>|null
     */
    private function replyResource(Message $m): ?array
    {
        $parent = $m->relationLoaded('replyTo') ? $m->replyTo : null;

        if (!$parent) {
            return null;
        }

        return [
            'id'          => $parent->id,
            'sender_name' => $parent->sender->name ?? '—',
            'mine'        => $parent->sender_id === Auth::id(),
            'preview'     => Str::limit($parent->previewLine(), 120),
            'deleted'     => $parent->isDeleted(),
        ];
    }
}
