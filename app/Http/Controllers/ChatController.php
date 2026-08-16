<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

            return [
                'conversation_id' => $c->id,
                'user_id' => $otherId,
                'name' => $other->name ?? '—',
                'last_body' => $last?->body,
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
            ->with('sender:id,name')
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

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $conversation = Conversation::between($me->id, $user->id);
        $message = $this->chat->sendMessage($conversation, $me, $data['body']);

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

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderByDesc('id')->limit(500)->get()
            ->sortBy('id')->values()
            ->map(fn(Message $m) => $this->messageResource($m));

        return response()->json([
            'conversation_id' => $conversation->id,
            'participants' => [$conversation->userOne->name ?? '—', $conversation->userTwo->name ?? '—'],
            'messages' => $messages,
        ]);
    }

    private function messageResource(Message $m): array
    {
        return [
            'id' => $m->id,
            'sender_id' => $m->sender_id,
            'sender_name' => $m->sender->name ?? '—',
            'body' => $m->body,
            'created_at' => $m->created_at->toIso8601String(),
        ];
    }
}
