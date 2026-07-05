<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowStageException;
use App\Models\Client;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowService $workflowService,
    ) {
    }

    public function index()
    {
        $stages = WorkflowStage::orderBy('sort_order')->get();

        return view('workflow.index', compact('stages'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('manage-workflow');
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'sort_order' => 'nullable|integer',
            'department' => 'nullable|string|max:60',
            'requires_approval' => 'nullable|boolean',
        ]);
        $stage = $this->workflowService->createStage($data);

        return response()->json(['success' => true, 'stage' => $stage]);
    }

    public function update(Request $request, WorkflowStage $stage): JsonResponse
    {
        $this->authorize('manage-workflow');
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'status' => 'boolean',
            'department' => 'nullable|string|max:60',
            'requires_approval' => 'nullable|boolean',
        ]);
        $updated = $this->workflowService->updateStage($stage, $data);

        return response()->json(['success' => true, 'stage' => $updated]);
    }

    public function destroy(WorkflowStage $stage): JsonResponse
    {
        $this->authorize('manage-workflow');
        $this->workflowService->deleteStage($stage);

        return response()->json(['success' => true]);
    }

    public function merge(Request $request, WorkflowStage $stage): JsonResponse
    {
        $this->authorize('manage-workflow');
        $data = $request->validate(['target_id' => 'required|exists:workflow_stages,id']);

        $target = WorkflowStage::findOrFail($data['target_id']);

        try {
            $this->workflowService->mergeStage($stage, $target);
        } catch (WorkflowStageException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function timeline(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $user = Auth::user();
        $rows = $this->workflowService->getTimeline($client);

        $data = array_map(function (array $row) use ($user) {
            $stage = $row['stage'];
            $progress = $row['progress'];
            $status = $row['status'];
            $locked = $row['locked'];

            $spillClass = match ($status) {
                'Approved' => 'spill-approved',
                'Submitted' => 'spill-submitted',
                'Need Revision' => 'spill-need-revision',
                'Rejected' => 'spill-rejected',
                'In Progress' => 'spill-in-progress',
                default => 'spill-pending',
            };

            $ownsDept = !$stage->department || $user->hasRole($stage->department) || $user->hasRole('Manager') || $user->hasRole('Super Admin');
            $canSubmit = !$locked && $ownsDept && $user->can('submit-stage') && in_array($status, ['Pending', 'In Progress', 'Need Revision']);
            $canApprove = !$locked && $ownsDept && $user->can('approve-stage') && $status === 'Submitted';

            return [
                'id' => $stage->id,
                'name' => $stage->name,
                'department' => $stage->department,
                'requires_approval' => $stage->requires_approval,
                'status' => $status,
                'spill_class' => $spillClass,
                'locked' => $locked,
                'current' => $row['current'],
                'overdue' => $row['overdue'],
                'payment_lock' => $row['payment_lock'],
                'submitted_at' => $progress?->submitted_at?->format('d M Y H:i'),
                'submitted_by' => $progress?->submittedBy?->name,
                'completed_at' => $progress?->completed_at?->format('d M Y H:i'),
                'completed_by' => $progress?->completedBy?->name,
                'rejection_reason' => $progress?->rejection_reason,
                'can_submit' => $canSubmit,
                'can_approve' => $canApprove,
                'can_reject' => $canApprove,
            ];
        }, $rows);

        return response()->json(['stages' => $data]);
    }

    public function toggleStage(Request $request, Client $client): JsonResponse
    {
        $this->authorize('manage-workflow');
        $data = $request->validate([
            'stage_id' => 'required|exists:workflow_stages,id',
            'completed' => 'required|boolean',
        ]);

        $result = $this->workflowService->toggleStage($client->id, $data['stage_id'], $data['completed']);

        return response()->json(['success' => true, 'progress' => $result['progress']]);
    }

    public function submitStage(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'stage_id' => 'required|exists:workflow_stages,id',
            'remarks' => 'nullable|string|max:2000',
        ]);

        try {
            $progress = $this->workflowService->submitStage($client, $data['stage_id'], Auth::user(), $data['remarks'] ?? null);
        } catch (WorkflowStageException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'status' => $progress->status]);
    }

    public function approveStage(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate(['stage_id' => 'required|exists:workflow_stages,id']);

        try {
            $progress = $this->workflowService->approveStage($client, $data['stage_id'], Auth::user());
        } catch (WorkflowStageException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'status' => $progress->status]);
    }

    public function rejectStage(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'stage_id' => 'required|exists:workflow_stages,id',
            'reason' => 'required|string|max:2000',
        ]);

        try {
            $progress = $this->workflowService->requestRevision($client, $data['stage_id'], Auth::user(), $data['reason']);
        } catch (WorkflowStageException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'status' => $progress->status]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('manage-workflow');
        $data = $request->validate(['stages' => 'required|array', 'stages.*.id' => 'required|exists:workflow_stages,id', 'stages.*.sort_order' => 'required|integer']);

        foreach ($data['stages'] as $item) {
            WorkflowStage::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['success' => true]);
    }
}
