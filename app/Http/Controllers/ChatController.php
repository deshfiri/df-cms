<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\MessageReaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
    ) {
    }

    public function index()
    {
        return view('chat.index');
    }

    /** The current user's conversations (only those with messages), newest first. */
    public function conversations(): JsonResponse
    {
        $me = Auth::id();

        $conversations = Conversation::forUser($me)
            ->whereNotNull('last_message_at')
            ->with(['userOne:id,name', 'userTwo:id,name', 'messages' => fn($q) => $q->latest('id')->limit(1)])
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $unread = Message::selectRaw('conversation_id, COUNT(*) as c')
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->where('sender_id', '!=', $me)
            ->whereNull('read_at')
            ->groupBy('conversation_id')
            ->pluck('c', 'conversation_id');

        $data = $conversations->map(function (Conversation $c) use ($me, $unread) {
            $otherId = $c->otherParticipantId($me);
            $other = $c->user_one_id === $otherId ? $c->userOne : $c->userTwo;
            $last = $c->messages->first();

            // An attachment-only message has no body, so show what was sent
            // rather than a blank row in the conversation list.
            $preview = $last?->body;
            if ($last && !$preview && $last->hasAttachment()) {
                $preview = $last->attachmentIsImage() ? '📷 Photo' : '📎 ' . $last->attachment_name;
            }

            return [
                'conversation_id' => $c->id,
                'user_id' => $otherId,
                'name' => $other->name ?? '—',
                'last_body' => $preview,
                'last_from_me' => $last && $last->sender_id === $me,
                'last_at' => $c->last_message_at?->diffForHumans(),
                'unread' => (int) ($unread[$c->id] ?? 0),
            ];
        });

        return response()->json(['conversations' => $data, 'unread_total' => $this->chat->unreadCountFor(Auth::user())]);
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
            ->get(['id', 'name']);

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
            ->with(['sender:id,name', 'reactions'])
            ->orderByDesc('id')->limit(200)->get()
            ->sortBy('id')->values()
            ->map(fn(Message $m) => $this->messageResource($m));

        return response()->json([
            'conversation_id' => $conversation->id,
            'other' => ['id' => $user->id, 'name' => $user->name],
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

        // Either half may be omitted, but not both — an empty message is not a
        // message. 20 MB matches the document limit used elsewhere.
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'required_without:body'],
        ]);

        $conversation = Conversation::between($me->id, $user->id);
        $message = $this->chat->sendMessage($conversation, $me, $data['body'] ?? null, $request->file('file'));

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
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get()
            ->map(fn(Conversation $c) => [
                'id' => $c->id,
                'user_one' => $c->userOne->name ?? '—',
                'user_two' => $c->userTwo->name ?? '—',
                'messages_count' => $c->messages_count,
                'last_at' => $c->last_message_at?->diffForHumans(),
            ]);

        return response()->json(['conversations' => $conversations]);
    }

    public function monitorShow(Conversation $conversation): JsonResponse
    {
        abort_unless(Auth::user()->can('monitor chats'), 403);

        $conversation->load(['userOne:id,name', 'userTwo:id,name']);

        // asMonitor: a retracted message still shows what was actually said,
        // flagged rather than hidden. That is the entire point of monitoring.
        $messages = $conversation->messages()
            ->with(['sender:id,name', 'reactions'])
            ->orderByDesc('id')->limit(500)->get()
            ->sortBy('id')->values()
            ->map(fn(Message $m) => $this->messageResource($m, asMonitor: true));

        return response()->json([
            'conversation_id' => $conversation->id,
            'participants' => [$conversation->userOne->name ?? '—', $conversation->userTwo->name ?? '—'],
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
        abort_unless(Storage::disk('local')->exists($message->attachment_path), 404);

        if ($message->attachmentIsImage()) {
            return Storage::disk('local')->response($message->attachment_path, $message->attachment_name, [
                'Content-Type'            => $message->attachment_mime,
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'",
            ]);
        }

        return Storage::disk('local')->download($message->attachment_path, $message->attachment_name);
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
            'created_at' => $m->created_at->toIso8601String(),
            'deleted' => $m->isDeleted(),
            'can_delete' => !$m->isDeleted() && $m->sender_id === Auth::id(),
            'reactions' => $m->relationLoaded('reactions') ? $m->reactionSummary(Auth::id()) : [],
            'attachment' => (!$redact && $m->hasAttachment()) ? [
                'name'     => $m->attachment_name,
                'size'     => $m->attachmentSizeForHumans(),
                'is_image' => $m->attachmentIsImage(),
                'url'      => route('chat.attachment', $m),
            ] : null,
        ];
    }
}
