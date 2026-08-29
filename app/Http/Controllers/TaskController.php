<?php

namespace App\Http\Controllers;

use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Models\Client;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskRevision;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $service,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Task::class);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $clients = Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number']);
        $users   = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $labels  = Label::orderBy('name')->get();

        // Counted over what this person may actually see, or the tiles would
        // advertise work they cannot open.
        $me = $request->user();

        $statusCounts = Task::query()->visibleTo($me)
            ->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status');
        $overdueCount = Task::query()->visibleTo($me)->overdue()->count();
        $reasonCategories = TaskRevision::$reasonCategories;

        // Work this person delegated that has been handed back to them.
        $awaitingMyReview = Task::where('created_by', $request->user()->id)
            ->where('status', Task::STATUS_SUBMITTED)
            ->count();

        return view('tasks.index', compact(
            'clients', 'users', 'labels', 'statusCounts', 'overdueCount', 'reasonCategories', 'awaitingMyReview'
        ));
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->service->create($request->validated());

        return response()->json(['success' => true, 'task' => $task]);
    }

    public function show(Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $task->load([
            'client:id,client_name,dfid_number',
            'assignedUser:id,name',
            'createdBy:id,name',
            'labels',
            'comments.user:id,name',
            'attachments.user:id,name',
            'activities.user:id,name',
            'revisions.requestedBy:id,name',
        ]);

        return response()->json(['task' => $task]);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $updated = $this->service->update($task, $request->validated());

        return response()->json(['success' => true, 'task' => $updated]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->authorize('manage tasks');
        $this->service->delete($task);

        return response()->json(['success' => true]);
    }

    /**
     * The assignee starts, pauses or resumes their own task.
     *
     * Narrower than update() on purpose: it accepts a status and nothing else,
     * so holding a task never becomes a way to edit its brief.
     */
    public function progress(Request $request, Task $task): JsonResponse
    {
        $this->authorize('progress', $task);

        $data = $request->validate([
            'status' => ['required', Rule::in(Task::$workingStatuses)],
        ]);

        $updated = $this->service->changeWorkingStatus($task, $request->user(), $data['status']);

        return response()->json(['success' => true, 'task' => $updated]);
    }

    /** The assignee hands the work back to whoever asked for it. */
    public function submit(Request $request, Task $task): JsonResponse
    {
        $this->authorize('submit', $task);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $updated = $this->service->submitForReview($task, $request->user(), $data['note'] ?? null);

        return response()->json(['success' => true, 'task' => $updated]);
    }

    /** The requester accepts the work, or sends it back with a reason. */
    public function review(Request $request, Task $task): JsonResponse
    {
        $this->authorize('review', $task);

        $data = $request->validate([
            'accept'          => ['required', 'boolean'],
            'note'            => ['nullable', 'string', 'max:1000'],
            'reason_category' => ['nullable', Rule::in(TaskRevision::$reasonCategories)],
        ]);

        $updated = $this->service->review($task, $request->user(), (bool) $data['accept'], $data);

        return response()->json(['success' => true, 'task' => $updated]);
    }

    public function storeRevision(Request $request, Task $task): JsonResponse
    {
        $this->authorize('manage tasks');

        $data = $request->validate([
            'reason_category' => ['required', Rule::in(TaskRevision::$reasonCategories)],
            'note'            => ['nullable', 'string', 'max:2000'],
        ]);

        $revision = $this->service->requestRevision($task, $data);

        return response()->json(['success' => true, 'revision' => $revision]);
    }

    public function storeComment(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $data = $request->validate(['comment' => 'required|string|max:2000']);
        $comment = $this->service->addComment($task, $data['comment']);

        return response()->json(['success' => true, 'comment' => $comment]);
    }

    public function destroyComment(Task $task, TaskComment $comment): JsonResponse
    {
        abort_if($comment->task_id !== $task->id, 404);
        abort_unless($comment->user_id === auth()->id() || auth()->user()->can('manage tasks'), 403, "Cannot delete another user's comment.");

        $this->service->deleteComment($comment);

        return response()->json(['success' => true]);
    }

    public function storeAttachment(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $request->validate(['file' => 'required|file|max:20480']);
        $attachment = $this->service->uploadAttachment($task, $request->file('file'));

        return response()->json(['success' => true, 'attachment' => $attachment]);
    }

    public function downloadAttachment(Task $task, TaskAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $task);
        abort_if($attachment->task_id !== $task->id, 404);
        $disk = Storage::disk($attachment->disk ?: 'local');
        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->download($attachment->file_path, $attachment->original_name);
    }

    public function destroyAttachment(Task $task, TaskAttachment $attachment): JsonResponse
    {
        abort_if($attachment->task_id !== $task->id, 404);
        abort_unless($attachment->user_id === auth()->id() || auth()->user()->can('manage tasks'), 403, "Cannot delete another user's attachment.");

        $this->service->deleteAttachment($attachment);

        return response()->json(['success' => true]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        // Authorization first, so no filter below can widen the result set.
        $query = Task::query()
            ->visibleTo($request->user())
            ->with(['client:id,client_name', 'assignedUser:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->boolean('overdue_only')) {
            $query->overdue();
        }
        // "Waiting on me": what I delegated and somebody has handed back.
        if ($request->boolean('review')) {
            $query->where('created_by', $request->user()->id)
                ->where('status', Task::STATUS_SUBMITTED);
        }

        $me = $request->user();
        $canManage = $me->can('manage tasks');

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('client', fn (Task $t) => e($t->client->client_name ?? '-'))
            ->addColumn('assigned', fn (Task $t) => e($t->assignedUser->name ?? 'Unassigned'))
            ->addColumn('priority_badge', fn (Task $t) => $this->priorityBadge($t->priority))
            ->addColumn('status_badge', fn (Task $t) => $this->statusBadge($t))
            ->addColumn('due', fn (Task $t) => $t->due_date?->format('d M Y') ?? '-')
            ->addColumn('actions', function (Task $t) use ($canManage, $me) {
                $html = '<button class="btn btn-sm px-2 py-1 task-view" data-id="' . $t->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View"><i class="bi bi-eye"></i></button> ';

                // Start / pause, for the person actually holding the task. Shown
                // only where the transition is one the endpoint would accept, so
                // the buttons never offer something that will be refused.
                if ($me->can('progress', $t)) {
                    if ($t->status !== 'In Progress') {
                        $label = $t->status === 'On Hold' ? 'Resume work' : 'Start work';
                        $html .= '<button class="btn btn-sm px-2 py-1 task-progress" data-id="' . $t->id . '" data-status="In Progress" style="background:var(--c-green-bg);border:1px solid var(--c-green);color:var(--c-green)" title="' . $label . '"><i class="bi bi-play-fill"></i></button> ';
                    } else {
                        $html .= '<button class="btn btn-sm px-2 py-1 task-progress" data-id="' . $t->id . '" data-status="On Hold" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Put on hold"><i class="bi bi-pause-fill"></i></button> ';
                    }
                }

                // The assignee hands it back; the requester rules on it. Both
                // are policy checks so the buttons match what the endpoints allow.
                if ($me->can('submit', $t)) {
                    $html .= '<button class="btn btn-sm px-2 py-1 task-submit" data-id="' . $t->id . '" data-title="' . e($t->title) . '" style="background:rgba(var(--primary-rgb),.1);border:1px solid var(--primary);color:var(--primary)" title="Submit for review"><i class="bi bi-send"></i></button> ';
                }
                if ($me->can('review', $t)) {
                    $html .= '<button class="btn btn-sm px-2 py-1 task-review" data-id="' . $t->id . '" data-title="' . e($t->title) . '" style="background:var(--c-yellow-bg);border:1px solid var(--c-yellow);color:var(--c-yellow)" title="Review submission"><i class="bi bi-clipboard-check"></i></button> ';
                }

                if ($canManage) {
                    $html .= '<button class="btn btn-sm px-2 py-1 task-edit" data-id="' . $t->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Edit"><i class="bi bi-pencil"></i></button> '
                        . '<button class="btn btn-sm px-2 py-1 task-delete" data-id="' . $t->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Delete"><i class="bi bi-trash"></i></button>';
                }

                return $html;
            })
            ->rawColumns(['priority_badge', 'status_badge', 'actions'])
            ->make(true);
    }

    private function priorityBadge(string $priority): string
    {
        $map = ['Low' => 'spill-hold', 'Medium' => 'spill-in-progress', 'High' => 'spill-warning', 'Urgent' => 'spill-rejected'];

        return '<span class="spill ' . ($map[$priority] ?? 'spill-hold') . '">' . e($priority) . '</span>';
    }

    private function statusBadge(Task $task): string
    {
        if ($task->is_overdue) {
            return '<span class="spill spill-rejected"><i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue</span>';
        }
        $map = [
            'Pending'     => 'spill-pending',
            'In Progress' => 'spill-in-progress',
            'On Hold'     => 'spill-hold',
            'Submitted'   => 'spill-warning',
            'Completed'   => 'spill-approved',
            'Cancelled'   => 'spill-rejected',
        ];

        return '<span class="spill ' . ($map[$task->status] ?? 'spill-pending') . '">' . e($task->status) . '</span>';
    }
}
