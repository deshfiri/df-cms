<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowStage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/**
 * Admin side of the generic workflow engine — build workflows, arrange stages,
 * assign users, activate, and track every item's progress. All gated by
 * 'manage workflows'.
 */
class FlowController extends Controller
{
    // All routes are gated by 'can:manage workflows' at the route-group level.

    public function index()
    {
        $flows = Flow::withCount(['stages', 'items'])
            ->with('creator:id,name')
            ->latest()
            ->get();

        return view('flows.index', compact('flows'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'description'    => ['nullable', 'string', 'max:2000'],
            // Whether this flow's work appears in the client portal.
            'client_visible' => ['nullable', 'boolean'],
        ]);
        $data['client_visible'] = $request->boolean('client_visible', true);

        $flow = Flow::create($data + ['created_by' => $request->user()->id]);

        return response()->json(['success' => true, 'id' => $flow->id]);
    }

    public function show(Flow $flow)
    {
        $flow->load(['stages.users:id,name']);
        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        // Same rule as the queue's start form, so both offer the same clients.
        $clients = FlowItemController::clientOptions(request()->user());

        return view('flows.show', compact('flow', 'users', 'clients'));
    }

    public function update(Request $request, Flow $flow): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'description'    => ['nullable', 'string', 'max:2000'],
            // Whether this flow's work appears in the client portal.
            'client_visible' => ['nullable', 'boolean'],
        ]);
        $data['client_visible'] = $request->boolean('client_visible', true);

        $flow->update($data);

        return response()->json(['success' => true]);
    }

    public function toggleActive(Flow $flow): JsonResponse
    {
        $flow->update(['is_active' => !$flow->is_active]);

        return response()->json(['success' => true, 'is_active' => $flow->is_active]);
    }

    public function destroy(Flow $flow): JsonResponse
    {
        $flow->delete(); // soft delete — items + history are retained

        return response()->json(['success' => true]);
    }

    // ── Stages ───────────────────────────────────────────────────────────

    public function storeStage(Request $request, Flow $flow): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);

        $position = (int) ($flow->stages()->max('position') ?? 0) + 1;
        $stage = $flow->stages()->create(['name' => $data['name'], 'position' => $position]);

        return response()->json(['success' => true, 'id' => $stage->id]);
    }

    public function updateStage(Request $request, FlowStage $stage): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $stage->update($data);

        return response()->json(['success' => true]);
    }

    public function destroyStage(FlowStage $stage): JsonResponse
    {
        $openHere = FlowItem::where('current_stage_id', $stage->id)
            ->where('status', FlowItem::STATUS_OPEN)->count();
        if ($openHere > 0) {
            return response()->json(['success' => false, 'message' => "{$openHere} open item(s) are currently at this stage. Move or complete them before deleting it."], 422);
        }

        $stage->delete();

        return response()->json(['success' => true]);
    }

    public function reorderStages(Request $request, Flow $flow): JsonResponse
    {
        $data = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'exists:flow_stages,id'],
        ]);

        // Reordering rewrites stage positions, which the engine uses to route
        // items — doing it mid-flow would misroute anything already in progress.
        if ($flow->items()->where('status', FlowItem::STATUS_OPEN)->exists()) {
            return response()->json(['success' => false, 'message' => 'Finish or cancel the open items in this workflow before reordering stages — it would misroute items already in flight.'], 422);
        }

        DB::transaction(function () use ($flow, $data) {
            foreach ($data['order'] as $i => $stageId) {
                FlowStage::where('id', $stageId)->where('flow_id', $flow->id)->update(['position' => $i + 1]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function assignUsers(Request $request, FlowStage $stage): JsonResponse
    {
        $data = $request->validate([
            'user_ids'   => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $stage->users()->sync($data['user_ids'] ?? []);

        return response()->json(['success' => true]);
    }

    // ── Tracker: every workflow item in one place ────────────────────────

    /**
     * Who may watch all workflow work.
     *
     * "view workflows" is the read-only seat — a manager who needs to see where
     * everything stands without being able to build or edit a workflow. Super
     * Admin passes through Gate::before.
     */
    public static function canTrack(User $user): bool
    {
        return $user->canAny(['view workflows', 'manage workflows']);
    }

    public function items(Request $request)
    {
        abort_unless(self::canTrack($request->user()), 403);

        if ($request->ajax()) {
            return $this->itemsTable($request);
        }

        return view('flows.items', [
            'flows'  => Flow::orderBy('name')->get(['id', 'name']),
            'stages' => FlowStage::with('flow:id,name')->orderBy('flow_id')->orderBy('position')->get(['id', 'flow_id', 'name', 'position']),
            'users'  => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'flowId' => $request->input('flow'),
            'canManage' => $request->user()->can('manage workflows'),
        ]);
    }

    private function itemsTable(Request $request): JsonResponse
    {
        // Stages nobody is assigned to: work sitting there cannot move on its own.
        $assignedStageIds = DB::table('flow_stage_user')->distinct()->pluck('flow_stage_id');
        $stageTotals      = FlowStage::selectRaw('flow_id, COUNT(*) as c')->groupBy('flow_id')->pluck('c', 'flow_id');

        $query = FlowItem::query()
            ->with(['client:id,client_name', 'flow:id,name', 'currentStage:id,name,position', 'creator:id,name', 'assignee:id,name'])
            ->withCount(['transitions', 'comments', 'attachments']);

        // Everything except the status pill, which the counts below speak for.
        if ($request->filled('flow')) {
            $query->where('flow_id', $request->flow);
        }
        if ($request->filled('stage')) {
            $query->where('current_stage_id', $request->stage);
        }
        if ($request->filled('client_id')) {
            $request->client_id === 'internal'
                ? $query->whereNull('client_id')
                : $query->where('client_id', $request->client_id);
        }
        if ($request->filled('assigned_to')) {
            $request->assigned_to === 'none'
                ? $query->whereNull('assigned_to')->where('status', FlowItem::STATUS_OPEN)
                : $query->where('assigned_to', $request->assigned_to);
        }

        $counts = $this->trackerCounts(clone $query, $assignedStageIds);

        // Status: defaults to what is actually running, which is the question
        // this page exists to answer.
        $status = $request->input('status', FlowItem::STATUS_OPEN);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($request->boolean('overdue')) {
            $query->where('status', FlowItem::STATUS_OPEN)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', today());
        }
        if ($request->boolean('unclaimed')) {
            $query->where('status', FlowItem::STATUS_OPEN)->whereNull('assigned_to');
        }
        if ($request->boolean('stranded')) {
            $query->where('status', FlowItem::STATUS_OPEN)->whereNotIn('current_stage_id', $assignedStageIds);
        }

        // Newest first until someone picks a column to sort by.
        if (!$request->filled('order')) {
            $query->orderByDesc('flow_items.id');
        }

        return DataTables::of($query)
            ->addColumn('item', function (FlowItem $i) {
                return '<div style="font-weight:600;color:var(--text)">' . e($i->title) . '</div>'
                    . '<div style="font-size:.68rem;color:' . ($i->client ? 'var(--primary)' : 'var(--text3)') . '">'
                    . ($i->client ? '<i class="bi bi-person-badge me-1"></i>' . e($i->client->client_name) : 'Internal')
                    . '</div>';
            })
            ->addColumn('flow_name', fn (FlowItem $i) => e($i->flow->name ?? '—'))
            ->addColumn('stage', function (FlowItem $i) use ($assignedStageIds, $stageTotals) {
                if (!$i->currentStage) {
                    return '<span style="color:var(--text3)">' . ($i->status === FlowItem::STATUS_COMPLETED ? 'Finished' : '—') . '</span>';
                }

                $total   = $stageTotals[$i->flow_id] ?? 0;
                $percent = $total ? (int) round(($i->currentStage->position - 1) / $total * 100) : 0;
                $stranded = $i->isOpen() && !$assignedStageIds->contains($i->current_stage_id);

                return '<div style="font-weight:600;color:var(--text2)">' . e($i->currentStage->name)
                    . ($total ? ' <span style="font-weight:400;color:var(--text3)">· ' . $i->currentStage->position . '/' . $total . '</span>' : '')
                    . ($stranded ? ' <span class="spill spill-warning" style="font-size:.56rem" title="Nobody is assigned to this stage">stranded</span>' : '')
                    . '</div>'
                    . '<div class="pay-bar" style="margin-top:4px"><span style="width:' . $percent . '%"></span></div>';
            })
            ->addColumn('who', fn (FlowItem $i) => $i->assignee
                ? e($i->assignee->name)
                : ($i->isOpen() ? '<span class="spill spill-hold">unclaimed</span>' : '<span style="color:var(--text3)">—</span>'))
            ->addColumn('due', function (FlowItem $i) {
                if (!$i->due_date) {
                    return '<span style="color:var(--text3)">—</span>';
                }
                $colour = $i->isOverdue() ? 'var(--c-red)' : ($i->due_date->isToday() ? 'var(--c-yellow)' : 'var(--text2)');

                return '<span style="font-weight:600;color:' . $colour . ';white-space:nowrap">' . $i->due_date->format('d M Y') . '</span>'
                    . ($i->isOverdue() ? '<div style="font-size:.64rem;color:var(--c-red)">' . $i->due_date->diffForHumans() . '</div>' : '');
            })
            ->addColumn('status_badge', fn (FlowItem $i) => '<span class="spill ' . match ($i->status) {
                FlowItem::STATUS_OPEN      => 'spill-running',
                FlowItem::STATUS_COMPLETED => 'spill-completed',
                default                    => 'spill-cancelled',
            } . '">' . e($i->status) . '</span>')
            ->addColumn('priority_badge', fn (FlowItem $i) => '<span class="spill ' . match ($i->priority) {
                'Urgent' => 'spill-cancelled',
                'High'   => 'spill-warning',
                'Normal' => 'spill-running',
                default  => 'spill-hold',
            } . '" style="font-size:.58rem">' . e($i->priority) . '</span>')
            ->addColumn('actions', fn (FlowItem $i) => '<button class="btn btn-sm px-2 py-1 item-details" data-id="' . $i->id
                . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Details"><i class="bi bi-eye"></i></button>')
            ->rawColumns(['item', 'stage', 'who', 'due', 'status_badge', 'priority_badge', 'actions'])
            ->orderColumn('due', 'due_date $1')
            ->with(['counts' => $counts])
            ->make(true);
    }

    /** @param  \Illuminate\Support\Collection<int,int>  $assignedStageIds */
    private function trackerCounts($query, $assignedStageIds): array
    {
        // select() first, replacing every column the list query carries. The list
        // adds withCount() sub-selects and flow_items.*, and MySQL's
        // only_full_group_by refuses to group a query that still selects them —
        // which is what broke this page. SQLite does not enforce the rule, so
        // the test suite never saw it.
        $byStatus = (clone $query)->reorder()
            ->select('status')->selectRaw('COUNT(*) as cnt')
            ->groupBy('status')->pluck('cnt', 'status');

        $open = fn () => (clone $query)->reorder()->where('status', FlowItem::STATUS_OPEN);

        return [
            'total'     => (int) $byStatus->sum(),
            'status'    => $byStatus->map(fn ($n) => (int) $n),
            'overdue'   => $open()->whereNotNull('due_date')->whereDate('due_date', '<', today())->count(),
            'unclaimed' => $open()->whereNull('assigned_to')->count(),
            'stranded'  => $open()->whereNotIn('current_stage_id', $assignedStageIds)->count(),
        ];
    }

    /**
     * Everything about one item, for the details panel on the tracker: where it
     * is, who has it, what has been attached or said, and every move it made.
     */
    public function itemDetails(Request $request, FlowItem $item): JsonResponse
    {
        abort_unless(self::canTrack($request->user()), 403);

        $item->load([
            'client:id,client_name', 'flow:id,name', 'currentStage:id,name,position',
            'creator:id,name', 'assignee:id,name',
            'flow.stages' => fn ($q) => $q->orderBy('position')->with('users:id,name'),
            'attachments.uploadedBy:id,name',
            'comments.user:id,name',
            'transitions' => fn ($q) => $q->with(['fromStage:id,name', 'toStage:id,name', 'movedBy:id,name']),
        ]);

        $current = $item->currentStage?->position;

        return response()->json([
            'id'          => $item->id,
            'title'       => $item->title,
            'description' => $item->description,
            'priority'    => $item->priority,
            'status'      => $item->status,
            'due_date'    => $item->due_date?->format('d M Y'),
            'is_overdue'  => $item->isOverdue(),
            'created_at'  => $item->created_at?->format('d M Y, H:i'),
            'completed_at' => $item->completed_at?->format('d M Y, H:i'),
            'client'      => $item->client ? ['name' => $item->client->client_name, 'url' => route('clients.show', $item->client_id)] : null,
            'flow'        => $item->flow?->name,
            'creator'     => $item->creator?->name,
            'assignee'    => $item->assignee?->name,
            'item_url'    => route('flow-items.show', $item),
            'stages'      => $item->flow?->stages->map(fn (FlowStage $s) => [
                'name'    => $s->name,
                'people'  => $s->users->pluck('name')->all(),
                'state'   => match (true) {
                    $current === null                => $item->status === FlowItem::STATUS_COMPLETED ? 'done' : 'todo',
                    $s->position <  $current         => 'done',
                    $s->position === $current        => 'current',
                    default                          => 'todo',
                },
            ])->values(),
            'attachments' => $item->attachments->map(fn ($a) => [
                'kind'  => $a->kind,
                'label' => $a->title ?: ($a->original_name ?: $a->url),
                'url'   => $a->isFile() ? route('flow-items.attachments.download', [$item, $a]) : $a->url,
                'preview_url' => $a->isPreviewableImage() ? route('flow-items.attachments.preview', [$item, $a]) : null,
                'body'  => $a->body,
                'by'    => $a->uploadedBy?->name,
            ])->values(),
            'comments'    => $item->comments->map(fn ($c) => [
                'by'   => $c->user?->name ?? '—',
                'body' => $c->body,
                'at'   => $c->created_at?->diffForHumans(),
            ])->values(),
            'history'     => $item->transitions->map(fn ($t) => [
                'from' => $t->fromStage?->name,
                'to'   => $t->toStage?->name,
                'by'   => $t->movedBy?->name ?? '—',
                'note' => $t->note,
                'at'   => $t->created_at ? \Illuminate\Support\Carbon::parse($t->created_at)->format('d M Y, H:i') : null,
            ])->values(),
        ]);
    }
}
