<?php

namespace App\Http\Controllers;

use App\Exceptions\FlowException;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowItemAttachment;
use App\Models\FlowItemComment;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * User side of the workflow engine — a person's own queue of items waiting on
 * them, starting new items, and moving items to the next stage. No global
 * visibility: users only ever see items at stages they're assigned to (plus
 * items they can view via FlowService::canView).
 */
class FlowItemController extends Controller
{
    public function __construct(
        private readonly FlowService $flow,
    ) {}

    public function queue(Request $request)
    {
        $user = $request->user();

        return view('flows.queue', [
            'items'     => $this->flow->myQueue($user),
            'startable' => $this->startableFlows($user),
        ]);
    }

    /**
     * A client's pipeline: the workflows running for them, where each one has
     * reached, and who is holding it. This is what replaces the old fixed
     * departmental timeline — the sequence is whatever an admin built under
     * Workflows, so it stays in step with how the team actually works.
     */
    public function clientWorkflow(Request $request, \App\Models\Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $items = FlowItem::where('client_id', $client->id)
            ->with([
                'flow:id,name',
                'flow.stages:id,flow_id,name,position',
                'currentStage:id,name,position',
                'assignee:id,name',
            ])
            ->latest('id')
            ->get()
            ->map(function (FlowItem $item) {
                $stages = $item->flow?->stages->sortBy('position')->values() ?? collect();
                $currentPosition = $item->currentStage->position ?? null;

                return [
                    'id'         => $item->id,
                    'title'      => $item->title,
                    'flow'       => $item->flow->name ?? '—',
                    'status'     => $item->status,
                    'priority'   => $item->priority,
                    'due_date'   => $item->due_date?->format('d M Y'),
                    'overdue'    => $item->isOverdue(),
                    'stage'      => $item->currentStage->name ?? null,
                    'assignee'   => $item->assignee->name ?? null,
                    'url'        => route('flow-items.show', $item),
                    // The whole sequence, so the client page shows the journey
                    // rather than only the step it happens to be on.
                    'stages'     => $stages->map(fn ($s) => [
                        'name' => $s->name,
                        'done' => $currentPosition !== null
                            ? $s->position < $currentPosition
                            : $item->status === 'Completed',
                        'current' => $item->current_stage_id === $s->id,
                    ])->all(),
                ];
            });

        return response()->json([
            'items'     => $items,
            'startable' => $this->startableFlows($request->user()),
        ]);
    }

    /** Everything the user has touched — items they created or moved — any status. */
    public function history(Request $request)
    {
        $me = $request->user()->id;

        $items = FlowItem::with(['flow:id,name', 'currentStage:id,name'])
            ->where(function ($q) use ($me) {
                $q->where('created_by', $me)
                    ->orWhereHas('transitions', fn ($t) => $t->where('moved_by', $me));
            })
            ->latest('updated_at')
            ->limit(200)
            ->get();

        return view('flows.history', ['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flow_id'     => ['required', 'exists:flows,id'],
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority'    => ['nullable', Rule::in(FlowItem::$priorities)],
            'due_date'    => ['nullable', 'date'],
            'note'        => ['nullable', 'string', 'max:2000'],
            'assign_to'   => ['nullable', 'integer', 'exists:users,id'],
            'client_id'   => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $flow = Flow::findOrFail($data['flow_id']);
        abort_unless($this->canStart($request->user(), $flow), 403, 'You cannot start items into this workflow.');

        // Running a workflow against a client exposes that client, so it needs
        // client visibility on top of permission to start the flow.
        if (!empty($data['client_id'])) {
            $this->authorize('view', \App\Models\Client::findOrFail($data['client_id']));
        }

        try {
            $this->flow->createItem($flow, $data, $request->user());
        } catch (FlowException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function show(Request $request, FlowItem $item)
    {
        abort_unless($this->flow->canView($request->user(), $item), 403);

        $item->load([
            'flow:id,name',
            'flow.stages:id,flow_id,name,position',
            'flow.stages.users:id,name',
            'currentStage:id,name,position',
            'creator:id,name',
            'transitions.fromStage:id,name,position',
            'transitions.toStage:id,name,position',
            'transitions.movedBy:id,name',
            'attachments.uploadedBy:id,name',
            'comments.user:id,name',
            'assignee:id,name',
        ]);

        $canAct = $this->flow->canAct($request->user(), $item);

        return view('flows.item', [
            'item'        => $item,
            'canAct'      => $canAct,
            'canClaim'    => $this->flow->canClaim($request->user(), $item),
            'canAttach'   => $this->flow->canAttach($request->user(), $item),
            'canManage'   => $this->flow->canManageItem($request->user(), $item),
            'stages'      => $item->flow?->stages->sortBy('position')->values() ?? collect(),
            'canSendBack' => $canAct && $item->currentStage
                && \App\Models\FlowStage::where('flow_id', $item->flow_id)
                    ->where('position', '<', $item->currentStage->position)->exists(),
        ]);
    }

    public function claim(Request $request, FlowItem $item): JsonResponse
    {
        try {
            $this->flow->claim($item, $request->user());
        } catch (FlowException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function release(Request $request, FlowItem $item): JsonResponse
    {
        try {
            $this->flow->release($item, $request->user());
        } catch (FlowException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function updateItem(Request $request, FlowItem $item): JsonResponse
    {
        abort_unless($this->flow->canManageItem($request->user(), $item), 403);

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority'    => ['nullable', Rule::in(FlowItem::$priorities)],
            'due_date'    => ['nullable', 'date'],
        ]);

        $item->update([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'] ?? 'Normal',
            'due_date'    => $data['due_date'] ?? null,
        ]);

        return response()->json(['success' => true]);
    }

    public function cancel(Request $request, FlowItem $item): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->flow->cancelItem($item, $request->user(), $data['reason'] ?? null);
        } catch (FlowException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function storeComment(Request $request, FlowItem $item): JsonResponse
    {
        abort_unless($this->flow->canView($request->user(), $item), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        FlowItemComment::create([
            'flow_item_id' => $item->id,
            'user_id'      => $request->user()->id,
            'body'         => $data['body'],
        ]);

        $this->flow->notifyNewComment($item, $request->user(), $data['body']);

        return response()->json(['success' => true]);
    }

    public function destroyComment(Request $request, FlowItem $item, FlowItemComment $comment): JsonResponse
    {
        abort_if($comment->flow_item_id !== $item->id, 404);
        abort_unless($comment->user_id === $request->user()->id || $request->user()->can('manage workflows'), 403);

        $comment->delete();

        return response()->json(['success' => true]);
    }

    // ── Attachments (files / links / notes that travel with the item) ────

    public function storeAttachment(Request $request, FlowItem $item): JsonResponse
    {
        abort_unless($this->flow->canAttach($request->user(), $item), 403);

        $data = $request->validate([
            'kind'  => ['required', Rule::in(['file', 'link', 'note'])],
            'title' => ['nullable', 'string', 'max:150'],
            'file'  => ['required_if:kind,file', 'file', 'max:51200'], // 50 MB — use a link for larger video
            'url'   => ['nullable', 'required_if:kind,link', 'url', 'max:2048'],
            'body'  => ['nullable', 'required_if:kind,note', 'string', 'max:5000'],
        ]);

        $payload = [
            'flow_item_id' => $item->id,
            'kind'         => $data['kind'],
            'title'        => $data['title'] ?? null,
            'uploaded_by'  => $request->user()->id,
        ];

        if ($data['kind'] === 'file') {
            $file   = $request->file('file');
            $stored = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $payload += [
                'original_name' => $file->getClientOriginalName(),
                'file_path'     => $file->storeAs('flow-attachments/' . $item->id, $stored, 'local'),
                'mime_type'     => $file->getMimeType(),
                'file_size'     => $file->getSize(),
            ];
        } elseif ($data['kind'] === 'link') {
            $payload['url'] = $data['url'];
        } else {
            $payload['body'] = $data['body'];
        }

        FlowItemAttachment::create($payload);

        return response()->json(['success' => true]);
    }

    public function downloadAttachment(Request $request, FlowItem $item, FlowItemAttachment $attachment): StreamedResponse
    {
        abort_unless($this->flow->canView($request->user(), $item), 403);
        abort_if($attachment->flow_item_id !== $item->id, 404);
        abort_unless($attachment->isFile() && Storage::disk('local')->exists($attachment->file_path), 404);

        return Storage::disk('local')->download($attachment->file_path, $attachment->original_name);
    }

    public function destroyAttachment(Request $request, FlowItem $item, FlowItemAttachment $attachment): JsonResponse
    {
        abort_if($attachment->flow_item_id !== $item->id, 404);
        abort_unless($attachment->uploaded_by === $request->user()->id || $request->user()->can('manage workflows'), 403);

        if ($attachment->isFile() && $attachment->file_path) {
            Storage::disk('local')->delete($attachment->file_path);
        }
        $attachment->delete();

        return response()->json(['success' => true]);
    }

    /** Who the item can be handed to next (or back to) — drives the hand-off dialog. */
    public function handoff(Request $request, FlowItem $item): JsonResponse
    {
        abort_unless($this->flow->canView($request->user(), $item), 403);

        return response()->json($this->flow->handoffOptions($item));
    }

    public function advance(Request $request, FlowItem $item): JsonResponse
    {
        $data = $request->validate([
            'note'      => ['nullable', 'string', 'max:2000'],
            'assign_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->flow->advance($item, $request->user(), $data['note'] ?? null, $data['assign_to'] ?? null);
        } catch (FlowException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function sendBack(Request $request, FlowItem $item): JsonResponse
    {
        $data = $request->validate([
            'reason'    => ['required', 'string', 'max:2000'],
            'assign_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->flow->sendBack($item, $request->user(), $data['reason'], $data['assign_to'] ?? null);
        } catch (FlowException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    private function canStart(User $user, Flow $flow): bool
    {
        if (!$flow->is_active) {
            return false;
        }
        if ($user->can('manage workflows')) {
            return true;
        }
        $first = $flow->firstStage();

        return $first !== null && $first->hasUser($user->id);
    }

    /**
     * Active flows this user may start an item into (admin, or assigned to the
     * first stage), each carrying that first stage's members so the creator can
     * address the new item to one of them.
     */
    private function startableFlows(User $user): Collection
    {
        $isAdmin = $user->can('manage workflows');

        return Flow::where('is_active', true)
            ->with(['stages' => fn ($q) => $q->orderBy('position')
                ->with(['users' => fn ($u) => $u->where('users.is_active', true)->select('users.id', 'users.name')])])
            ->get()
            ->filter(function (Flow $f) use ($user, $isAdmin) {
                if ($isAdmin) {
                    return $f->stages->isNotEmpty();
                }
                $first = $f->stages->first();

                return $first && $first->users->contains('id', $user->id);
            })
            ->map(fn (Flow $f) => [
                'id'          => $f->id,
                'name'        => $f->name,
                'first_stage' => [
                    'name'  => $f->stages->first()->name,
                    'users' => $f->stages->first()->users
                        ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                        ->sortBy('name')->values()->all(),
                ],
            ])
            ->values();
    }
}
