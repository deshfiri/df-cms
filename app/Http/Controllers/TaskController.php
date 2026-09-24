<?php

namespace App\Http\Controllers;

use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Models\Client;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskNote;
use App\Models\TaskRevision;
use App\Models\User;
use App\Services\Storage\StoredFileResponse;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
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

        // Links from before tasks had their own page (/tasks?task=12), including
        // notifications already stored in the database.
        if ($request->filled('task') && ctype_digit((string) $request->query('task'))) {
            return redirect()->route('tasks.show', (int) $request->query('task'));
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

    /** The sidebar badge: what is waiting on the signed-in person. */
    public function navCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        return response()->json(Task::pendingCountsFor($request->user()));
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->service->create($request->validated());

        return response()->json(['success' => true, 'task' => $task]);
    }

    /**
     * One task — its own page for a browser, JSON for scripts (the edit dialog,
     * the live timer's resync).
     *
     * Authorized first either way, so nothing confirms that a task you may not
     * see exists. Opening a task is never logged: looking is not involvement.
     */
    public function show(Request $request, Task $task): JsonResponse|View
    {
        $this->authorize('view', $task);

        if ($request->ajax() || $request->expectsJson()) {
            return $this->showJson($request, $task);
        }

        $me = $request->user();

        $task->load([
            'clients:id,client_name,dfid_number',
            'assignees:id,name,avatar',
            'createdBy:id,name,avatar',
            'updatedBy:id,name',
            'labels',
            'comments.user:id,name,avatar',
            'attachments.user:id,name',
            'notes.user:id,name',
            'activities.user:id,name',
            'revisions.requestedBy:id,name',
            'involvements.user:id,name,avatar',
        ]);

        $canUpdate = $me->can('update', $task);

        return view('tasks.show', [
            'task'  => $task,
            'timer' => $task->timer(),
            'can'   => [
                'progress'        => $me->can('progress', $task),
                'submit'          => $me->can('submit', $task),
                'review'          => $me->can('review', $task),
                'requestRevision' => $me->can('requestRevision', $task),
                'update'          => $canUpdate,
                'delete'          => $me->can('delete', $task),
                // Removing others' files and comments, and adding to a closed task.
                'manage'          => $me->can('moderate', $task),
                // Work shares are performance data: shown to those who manage
                // tasks or read performance, not to everyone on the task.
                'shares'          => $me->canAny(['manage all tasks', 'view performance']),
            ],
            'awaitingSubmissionFile' => $task->requires_attachment && !$this->service->hasSubmissionFile($task),
            // Only needed for the edit dialog.
            'clients' => $canUpdate ? Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number']) : collect(),
            'users'   => $canUpdate ? User::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'labels'  => $canUpdate ? Label::orderBy('name')->get() : collect(),
            'reasonCategories' => TaskRevision::$reasonCategories,
        ]);
    }

    private function showJson(Request $request, Task $task): JsonResponse
    {
        // The live counter resyncs every minute; it needs the clock, not the history.
        if ($request->boolean('timer_only')) {
            return response()->json(['status' => $task->status, 'timer' => $task->timer()]);
        }

        $task->load([
            'clients:id,client_name,dfid_number',
            'assignees:id,name',
            'createdBy:id,name',
            'labels',
            'comments.user:id,name',
            'attachments.user:id,name',
            'activities.user:id,name',
            'revisions.requestedBy:id,name',
        ]);

        return response()->json(['task' => $task, 'timer' => $task->timer()]);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $updated = $this->service->update($task, $request->validated());

        return response()->json(['success' => true, 'task' => $updated]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->authorize('delete', $task);
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

    /**
     * The assignee hands the work back to whoever asked for it, optionally with
     * the files that make up the work.
     */
    public function submit(Request $request, Task $task): JsonResponse
    {
        $this->authorize('submit', $task);

        $data = $request->validate([
            'note'    => ['nullable', 'string', 'max:1000'],
            'files'   => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:20480'],
            // Files the submit dialog already uploaded, one request each.
            'attachment_ids'   => ['nullable', 'array', 'max:100'],
            'attachment_ids.*' => ['integer'],
        ], [
            'files.max'   => 'Hand in up to 10 files at a time.',
            'files.*.max' => 'Each file can be up to 20 MB.',
        ]);

        $updated = $this->service->submitForReview(
            $task, $request->user(), $data['note'] ?? null, $request->file('files', []), $data['attachment_ids'] ?? [],
        );

        return response()->json([
            'success' => true,
            'message' => 'Submitted for review.',
            'task'    => $updated,
            'timer'   => $updated->timer(),
        ]);
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
        // Whoever asked for the work, or the person who handed it in, may send
        // it back for rework — see TaskPolicy::requestRevision().
        $this->authorize('requestRevision', $task);

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
        $this->authorize('view', $task);
        abort_if((int) $comment->task_id !== (int) $task->id, 404);
        abort_unless((int) $comment->user_id === (int) auth()->id() || auth()->user()->can('moderate', $task), 403, "Cannot delete another user's comment.");

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
        abort_if((int) $attachment->task_id !== (int) $task->id, 404);

        // Type and size come from the upload record, not a round trip to the
        // disk — see StoredFileResponse for why that broke CDN downloads.
        return StoredFileResponse::download(
            $attachment->disk,
            (string) $attachment->file_path,
            (string) $attachment->original_name,
            $attachment->mime_type,
            $attachment->file_size,
        );
    }

    /** An image attachment shown in the page — same authorization as a download. */
    public function previewAttachment(Task $task, TaskAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $task);
        abort_if((int) $attachment->task_id !== (int) $task->id, 404);

        return StoredFileResponse::preview(
            $attachment->disk,
            (string) $attachment->file_path,
            (string) $attachment->original_name,
            $attachment->mime_type,
            $attachment->file_size,
        );
    }

    public function destroyAttachment(Task $task, TaskAttachment $attachment): JsonResponse
    {
        // Having uploaded to a task you can no longer see is not a way back in.
        $this->authorize('view', $task);
        abort_if((int) $attachment->task_id !== (int) $task->id, 404);
        abort_unless((int) $attachment->user_id === (int) auth()->id() || auth()->user()->can('moderate', $task), 403, "Cannot delete another user's attachment.");

        $this->service->deleteAttachment($attachment);

        return response()->json(['success' => true]);
    }

    /** A link or a note shared beside the files — open to anyone on the task, like a file. */
    public function storeNote(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $data = $request->validate(
            ['body' => 'required|string|max:2000'],
            ['body.required' => 'Paste a link or write a note first.'],
        );
        $note = $this->service->addNote($task, $data['body']);

        return response()->json(['success' => true, 'note' => $note]);
    }

    public function destroyNote(Task $task, TaskNote $note): JsonResponse
    {
        $this->authorize('view', $task);
        abort_if((int) $note->task_id !== (int) $task->id, 404);
        abort_unless((int) $note->user_id === (int) auth()->id() || auth()->user()->can('moderate', $task), 403, "Cannot delete another user's link or note.");

        $this->service->deleteNote($note);

        return response()->json(['success' => true]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $me = $request->user();

        // Authorization first, so no filter below can widen the result set.
        $query = Task::query()
            ->visibleTo($me)
            ->with(['clients:id,client_name', 'assignees:id,name']);

        // Every filter except the status itself: the pill counts have to say how
        // much sits under each status within the *other* filters, or picking one
        // status would zero all the others.
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('assigned_to')) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', $request->assigned_to));
        }
        if ($request->filled('client_id')) {
            $query->whereHas('clients', fn ($q) => $q->where('clients.id', $request->client_id));
        }

        $counts = $this->pillCounts($query, $me);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->boolean('overdue_only')) {
            $query->overdue();
        }
        // "Waiting on me": what I delegated and somebody has handed back.
        if ($request->boolean('review')) {
            $query->where('created_by', $me->id)
                ->where('status', Task::STATUS_SUBMITTED);
        }

        return DataTables::of($query)
            // The task number, sortable (the list opens newest first on it).
            ->addColumn('number', fn (Task $t) => '<span style="font-family:monospace;color:var(--text3)">#' . $t->id . '</span>')
            ->addColumn('title_link', fn (Task $t) => '<a class="task-title-link" href="' . e(route('tasks.show', $t)) . '">' . e($t->title) . '</a>'
                . ($t->requires_attachment ? ' <i class="bi bi-paperclip" style="color:var(--text3);font-size:.72rem" title="Submission needs a file"></i>' : ''))
            ->addColumn('client', fn (Task $t) => $t->clients->isEmpty() ? '-' : e($t->clients->pluck('client_name')->join(', ')))
            ->addColumn('assigned', fn (Task $t) => $t->assignees->isEmpty() ? 'Unassigned' : e($t->assignees->pluck('name')->join(', ')))
            ->addColumn('priority_badge', fn (Task $t) => $this->priorityBadge($t->priority))
            ->addColumn('status_badge', fn (Task $t) => $this->statusBadge($t))
            ->addColumn('due', function (Task $t) {
                if (!$t->due_at) {
                    return '-';
                }
                // An exact deadline shows its time, in the viewer's zone (see the page script).
                return $t->dueHasTime()
                    ? '<time class="local-dt" datetime="' . e($t->due_at->toIso8601String()) . '">' . e($t->due_at->format('d M Y, H:i')) . '</time>'
                    : e($t->due_date?->format('d M Y') ?? '-');
            })
            ->addColumn('actions', function (Task $t) use ($me) {
                $html = '<a href="' . e(route('tasks.show', $t)) . '" class="btn btn-sm px-2 py-1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Open"><i class="bi bi-box-arrow-up-right"></i></a> ';

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
                    // Shown to the assignee throughout, but only live once work has started.
                    $blocker = $t->submitBlocker($me);
                    $html .= $blocker
                        ? '<span class="d-inline-block" tabindex="0" title="' . e($blocker) . '"><button class="btn btn-sm px-2 py-1" disabled style="border:1px solid var(--border);color:var(--text3);pointer-events:none"><i class="bi bi-send"></i></button></span> '
                        : '<button class="btn btn-sm px-2 py-1 task-submit" data-id="' . $t->id . '" data-title="' . e($t->title) . '" data-requires="' . ($t->requires_attachment ? 1 : 0) . '" style="background:rgba(var(--primary-rgb),.1);border:1px solid var(--primary);color:var(--primary)" title="Submit for review"><i class="bi bi-send"></i></button> ';
                }
                if ($me->can('review', $t)) {
                    $html .= '<button class="btn btn-sm px-2 py-1 task-review" data-id="' . $t->id . '" data-title="' . e($t->title) . '" style="background:var(--c-yellow-bg);border:1px solid var(--c-yellow);color:var(--c-yellow)" title="Review submission"><i class="bi bi-clipboard-check"></i></button> ';
                }

                // Per task: oversight edits anything, everyone else only what they created.
                if ($me->can('update', $t)) {
                    $html .= '<button class="btn btn-sm px-2 py-1 task-edit" data-id="' . $t->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Edit"><i class="bi bi-pencil"></i></button> ';
                }
                if ($me->can('delete', $t)) {
                    $html .= '<button class="btn btn-sm px-2 py-1 task-delete" data-id="' . $t->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Delete"><i class="bi bi-trash"></i></button>';
                }

                return $html;
            })
            ->rawColumns(['number', 'title_link', 'priority_badge', 'status_badge', 'due', 'actions'])
            // Ride along with the table so the filter pills stay true after every
            // refresh — no second request, and never out of step with the rows.
            ->with(['counts' => $counts])
            ->make(true);
    }

    /**
     * Live pill counts for the filters in play.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $filtered  every filter except status
     */
    private function pillCounts($filtered, User $me): array
    {
        // select() replaces any columns the list query carries, so the GROUP BY
        // stays valid under MySQL's only_full_group_by.
        $byStatus = (clone $filtered)->reorder()
            ->select('status')->selectRaw('COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return [
            'total'   => (int) $byStatus->sum(),
            'status'  => $byStatus->map(fn ($n) => (int) $n),
            'overdue' => (clone $filtered)->reorder()->overdue()->count(),
            'review'  => Task::where('created_by', $me->id)->where('status', Task::STATUS_SUBMITTED)->count(),
        ];
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
            'Pending'             => 'spill-pending',
            'In Progress'         => 'spill-in-progress',
            'On Hold'             => 'spill-hold',
            'Partially Submitted' => 'spill-hold',
            'Submitted'           => 'spill-warning',
            'Completed'           => 'spill-approved',
            'Cancelled'           => 'spill-rejected',
        ];

        return '<span class="spill ' . ($map[$task->status] ?? 'spill-pending') . '">' . e($task->status) . '</span>';
    }
}
