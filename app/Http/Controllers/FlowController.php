<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowStage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return view('flows.show', compact('flow', 'users'));
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

    // ── Items tracker (admin full visibility) ────────────────────────────

    public function items(Request $request)
    {
        $flows = Flow::orderBy('name')->get(['id', 'name']);
        $flowId = $request->input('flow');

        $items = FlowItem::with(['flow:id,name', 'currentStage:id,name,position', 'creator:id,name', 'assignee:id,name'])
            ->withCount('transitions')
            ->when($flowId, fn ($q) => $q->where('flow_id', $flowId))
            ->latest()
            ->limit(300)
            ->get();

        // Stage counts per flow to render progress ("stage 2 of 5").
        $stageTotals = FlowStage::selectRaw('flow_id, COUNT(*) as c')->groupBy('flow_id')->pluck('c', 'flow_id');

        // Stages that have at least one assignee — items at any other stage are "stranded".
        $assignedStageIds = DB::table('flow_stage_user')->distinct()->pluck('flow_stage_id')->all();

        return view('flows.items', compact('items', 'flows', 'flowId', 'stageTotals', 'assignedStageIds'));
    }
}
