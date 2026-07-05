<?php

namespace App\Http\Controllers;

use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Models\Client;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $statusCounts = Task::selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status');
        $overdueCount = Task::overdue()->count();

        return view('tasks.index', compact('clients', 'users', 'labels', 'statusCounts', 'overdueCount'));
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
        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return Storage::disk('local')->download($attachment->file_path, $attachment->original_name);
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
        $query = Task::query()->with(['client:id,client_name', 'assignedUser:id,name']);

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

        $canManage = $request->user()->can('manage tasks');

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('client', fn (Task $t) => e($t->client->client_name ?? '-'))
            ->addColumn('assigned', fn (Task $t) => e($t->assignedUser->name ?? 'Unassigned'))
            ->addColumn('priority_badge', fn (Task $t) => $this->priorityBadge($t->priority))
            ->addColumn('status_badge', fn (Task $t) => $this->statusBadge($t))
            ->addColumn('due', fn (Task $t) => $t->due_date?->format('d M Y') ?? '-')
            ->addColumn('actions', function (Task $t) use ($canManage) {
                $html = '<button class="btn btn-sm px-2 py-1 task-view" data-id="' . $t->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View"><i class="bi bi-eye"></i></button> ';
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
            'Completed'   => 'spill-approved',
            'Cancelled'   => 'spill-rejected',
        ];

        return '<span class="spill ' . ($map[$task->status] ?? 'spill-pending') . '">' . e($task->status) . '</span>';
    }
}
