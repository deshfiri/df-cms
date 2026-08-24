<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\MetaWhatsAppClient;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The unified inbox.
 *
 * Every endpoint authorises before it reads, and every listing is scoped in SQL
 * through WhatsAppConversation::scopeVisibleTo — a conversation id in a URL is a
 * request, not a grant, and nothing is ever fetched and then hidden in the UI.
 *
 * Note what the send endpoint does *not* accept: no brand, no account, no phone
 * number id. Those are derived from the conversation, so there is no request
 * shape that could send a message from another brand's number.
 */
class WhatsAppInboxController extends Controller
{
    public function __construct(
        private readonly WhatsAppConversationService $conversations,
        private readonly WhatsAppOutboundService $outbound,
        private readonly MetaWhatsAppClient $client,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', WhatsAppConversation::class);

        return view('whatsapp.inbox', [
            // Only brands this user could actually reach a conversation on —
            // the filter must not advertise brands they cannot see.
            'brands'  => $this->visibleBrands($request->user()),
            'agents'  => $this->assignableAgents($request->user()),
            'canAssign' => $request->user()->can('assign whatsapp'),
            'canReply'  => $request->user()->can('reply whatsapp'),
            'statuses'  => WhatsAppConversation::STATUSES,
            'openConversationId' => $request->integer('conversation') ?: null,
        ]);
    }

    /** Conversation list, filtered and paginated server-side. */
    public function conversations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WhatsAppConversation::class);

        $filters = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'status'   => ['nullable', Rule::in(WhatsAppConversation::STATUSES)],
            'search'   => ['nullable', 'string', 'max:120'],
            'filter'   => ['nullable', Rule::in(['all', 'unread', 'mine', 'unassigned'])],
            'page'     => ['nullable', 'integer', 'min:1'],
        ]);

        $me = $request->user();

        $query = WhatsAppConversation::query()
            // Authorization first, so no later filter can widen the result set.
            ->visibleTo($me)
            ->with(['contact:id,wa_id,phone,name,profile_name', 'brand:id,name', 'assignee:id,name',
                    'account:id,display_phone_number'])
            ->forBrand($filters['brand_id'] ?? null)
            ->status($filters['status'] ?? null)
            ->search($filters['search'] ?? null);

        $query = match ($filters['filter'] ?? 'all') {
            'unread'     => $query->unread(),
            'mine'       => $query->where('assigned_user_id', $me->id),
            'unassigned' => $query->whereNull('assigned_user_id'),
            default      => $query,
        };

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->paginate(30)
            ->through(fn (WhatsAppConversation $c) => $this->conversationResource($c));

        return response()->json([
            'conversations' => $conversations->items(),
            'has_more'      => $conversations->hasMorePages(),
            'current_page'  => $conversations->currentPage(),
            'unread_total'  => $this->conversations->unreadCountFor($me),
        ]);
    }

    /** One thread, newest messages first, paginated. */
    public function show(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load(['contact', 'brand:id,name', 'assignee:id,name', 'account']);

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->paginate(40, ['*'], 'page', $request->integer('page') ?: 1);

        // Opening the thread is what clears it, and tells the customer we looked.
        if ($request->boolean('mark_read', true)) {
            $this->markReadOnMeta($conversation);
            $this->conversations->markRead($conversation);
        }

        return response()->json([
            'conversation' => $this->conversationResource($conversation, detailed: true),
            // Reversed so the client renders oldest-first without re-sorting.
            'messages'     => collect($messages->items())->reverse()->values()
                ->map(fn (WhatsAppMessage $m) => $this->messageResource($m)),
            'has_more'     => $messages->hasMorePages(),
            'can_reply'    => $request->user()->can('reply', $conversation),
            'reply_block'  => app(\App\Policies\WhatsAppConversationPolicy::class)
                ->replyRefusalReason($request->user(), $conversation),
            'within_window' => $conversation->withinServiceWindow(),
        ]);
    }

    /**
     * Send a reply.
     *
     * Takes the conversation and a body. Nothing else — see the class docblock.
     */
    public function send(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $this->authorize('reply', $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4096', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:16384', 'required_without:body'],
        ]);

        $message = $request->hasFile('file')
            ? $this->outbound->sendMedia($conversation, $request->user(), $request->file('file'), $data['body'] ?? null)
            : $this->outbound->sendText($conversation, $request->user(), $data['body']);

        return response()->json([
            'success' => true,
            'message' => $this->messageResource($message),
        ]);
    }

    public function assign(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $this->authorize('assign', $conversation);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = isset($data['user_id']) ? User::find($data['user_id']) : null;

        // Assigning to someone who cannot open WhatsApp would silently bury the
        // conversation where nobody can answer it.
        if ($assignee && !$assignee->can('view whatsapp') && !$assignee->can('view all whatsapp')) {
            return response()->json([
                'success' => false,
                'message' => $assignee->name . ' does not have access to WhatsApp conversations.',
            ], 422);
        }

        $this->conversations->assign($conversation, $assignee, $request->user());

        return response()->json(['success' => true]);
    }

    public function updateStatus(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $this->authorize('changeStatus', $conversation);

        $data = $request->validate([
            'status' => ['required', Rule::in(WhatsAppConversation::STATUSES)],
        ]);

        $this->conversations->changeStatus($conversation, $data['status'], $request->user());

        return response()->json(['success' => true]);
    }

    /**
     * Stream a message's media.
     *
     * Authorized per request against the conversation, and served from our own
     * storage — customer media is never a public URL (spec §35).
     */
    public function media(WhatsAppMessage $message)
    {
        $conversation = $message->conversation;

        abort_unless($conversation !== null, 404);
        $this->authorize('view', $conversation);
        abort_unless($message->hasMedia(), 404);

        $disk = Storage::disk($message->media_disk ?: 'local');

        abort_unless($disk->exists($message->media_path), 404);

        // Inline for images and audio so they render in the thread; everything
        // else is forced to download, so an uploaded .html can never execute in
        // this application's own origin.
        $inline = str_starts_with((string) $message->media_mime, 'image/')
            || str_starts_with((string) $message->media_mime, 'audio/')
            || str_starts_with((string) $message->media_mime, 'video/');

        if ($inline) {
            return $disk->response($message->media_path, $message->media_name, [
                'Content-Type'            => $message->media_mime,
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'",
            ]);
        }

        return $disk->download($message->media_path, $message->media_name ?: 'attachment');
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** Let the customer see their message was read. Never blocks opening a thread. */
    private function markReadOnMeta(WhatsAppConversation $conversation): void
    {
        if ($conversation->unread_count === 0 || !$conversation->account?->isUsable()) {
            return;
        }

        $latest = $conversation->messages()
            ->where('direction', WhatsAppMessage::DIRECTION_IN)
            ->whereNotNull('wamid')
            ->latest('id')
            ->first();

        if (!$latest) {
            return;
        }

        try {
            $this->client->markRead($conversation->account, $latest->wamid);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Brands this user could see a conversation on.
     *
     * Derived from the same visibility scope as the list itself, so the filter
     * can never offer a brand whose conversations would be refused.
     *
     * @return \Illuminate\Support\Collection<int,Brand>
     */
    private function visibleBrands(User $user)
    {
        if ($user->hasRole('Super Admin') || $user->can('view all whatsapp')) {
            return Brand::whereHas('whatsappAccounts')->orderBy('name')->get(['id', 'name']);
        }

        return Brand::whereIn(
            'id',
            WhatsAppConversation::query()->visibleTo($user)->select('brand_id'),
        )->orderBy('name')->get(['id', 'name']);
    }

    /** @return \Illuminate\Support\Collection<int,User> */
    private function assignableAgents(User $user)
    {
        if (!$user->can('assign whatsapp')) {
            return collect();
        }

        return User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (User $u) => $u->can('view whatsapp') || $u->can('view all whatsapp'))
            ->values();
    }

    /** @return array<string,mixed> */
    private function conversationResource(WhatsAppConversation $c, bool $detailed = false): array
    {
        $base = [
            'id'            => $c->id,
            'contact_name'  => $c->contact?->displayName() ?? '—',
            'contact_phone' => $c->contact?->formattedPhone() ?? '—',
            'brand'         => $c->brand?->name,
            'brand_id'      => $c->brand_id,
            'wa_number'     => $c->account?->display_phone_number,
            'status'        => $c->status,
            'priority'      => $c->priority,
            'unread'        => $c->unread_count,
            'preview'       => $c->last_message_preview,
            'last_at'       => $c->last_message_at?->diffForHumans(),
            'assignee'      => $c->assignee?->name,
            'assignee_id'   => $c->assigned_user_id,
        ];

        if (!$detailed) {
            return $base;
        }

        return $base + [
            'wa_id'          => $c->contact?->wa_id,
            'assigned_at'    => $c->assigned_at?->format('d M Y, h:i A'),
            'created_at'     => $c->created_at->format('d M Y'),
            'window_expires' => $c->last_customer_message_at
                ?->addHours(WhatsAppConversation::SERVICE_WINDOW_HOURS)
                ->diffForHumans(),
        ];
    }

    /** @return array<string,mixed> */
    private function messageResource(WhatsAppMessage $m): array
    {
        return [
            'id'         => $m->id,
            'direction'  => $m->direction,
            'type'       => $m->type,
            'body'       => $m->body,
            'status'     => $m->status,
            'error'      => $m->error_message,
            'agent'      => $m->sender?->name,
            'template'   => $m->template_name,
            'created_at' => $m->created_at->toIso8601String(),
            'media'      => $m->hasMedia() ? [
                'url'      => route('whatsapp.media', $m),
                'name'     => $m->media_name,
                'mime'     => $m->media_mime,
                'size'     => $m->mediaSizeForHumans(),
                'is_image' => str_starts_with((string) $m->media_mime, 'image/'),
                'is_audio' => str_starts_with((string) $m->media_mime, 'audio/'),
                'is_video' => str_starts_with((string) $m->media_mime, 'video/'),
            ] : null,
            // Media that has been received but not yet pulled down from Meta.
            'media_pending' => !$m->hasMedia() && filled($m->media_id),
        ];
    }
}
