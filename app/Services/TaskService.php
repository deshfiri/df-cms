<?php

namespace App\Services;

use App\Exceptions\WorkloadLimitException;
use App\Models\PerformanceSetting;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskNote;
use App\Models\TaskRevision;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskReviewed;
use App\Notifications\TaskSubmitted;
use App\Services\Storage\UploadStaging;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TaskService
{
    public function __construct(
        private readonly ActivityLogService       $activityLog,
        private readonly WorkloadService          $workload,
        private readonly UploadStaging            $uploads,
        private readonly TaskInvolvementService   $involvement,
    ) {}

    public function create(array $data): Task
    {
        $task = DB::transaction(function () use ($data) {
            $labelIds = $data['label_ids'] ?? [];
            unset($data['label_ids']);
            $clientIds = $data['client_ids'] ?? [];
            unset($data['client_ids']);

            $data = $this->applyWorkloadRules($this->applyDeadline($data));
            $this->refuseSelfAssignment($data['assigned_to'] ?? null);
            $data['created_by'] = Auth::id();
            $task = Task::create($data);

            if ($labelIds) {
                $task->labels()->sync($labelIds);
            }
            if ($clientIds) {
                $task->clients()->sync($clientIds);
            }

            $this->logActivity($task, 'Created', "Task \"{$task->title}\" created", event: 'created', meta: [
                'assigned_to' => $task->assigned_to,
                'due_at'      => $task->due_at?->toIso8601String(),
            ]);
            $this->logForClients($task, 'Created', null, ['title' => $task->title]);

            return $task->load('assignedUser:id,name', 'clients:id,client_name', 'labels');
        });

        // After commit: a notification for a task that rolled back would be a lie,
        // and the broadcast leaves the process immediately.
        $this->notifyAssignee($task);

        return $task;
    }

    /**
     * Not gated by change approval. Editing a task — above all marking your own
     * work complete — is the most routine action in the app, and requiring a
     * manager to sign each one off meant nobody could finish anything without
     * waiting. Task history is covered by the activity log instead.
     */
    public function update(Task $task, array $data): Task
    {
        $previousAssignee = $task->assigned_to;

        if (array_key_exists('assigned_to', $data)) {
            $this->refuseSelfAssignment($data['assigned_to'], $task);
        }

        $updated = DB::transaction(function () use ($task, $data) {
            $labelIds = $data['label_ids'] ?? null;
            unset($data['label_ids']);
            $clientIds = array_key_exists('client_ids', $data) ? $data['client_ids'] : null;
            unset($data['client_ids']);
            $data = $this->applyDeadline($data);

            $old = $task->only(['status', 'priority', 'assigned_to', 'due_date', 'due_at']);
            $data['updated_by'] = Auth::id();

            if (($data['status'] ?? null) === 'Completed' && $task->status !== 'Completed') {
                $data['completion_date'] = now()->toDateString();
            }

            $task->update($data);

            if ($labelIds !== null) {
                $task->labels()->sync($labelIds);
            }
            if ($clientIds !== null) {
                $task->clients()->sync($clientIds);
            }

            // The full before/after stays on one "Updated" row (deadline-extension
            // history reads it); the changes that matter to who did what are also
            // logged as their own events.
            $this->logActivity($task, 'Updated', 'Task updated', $old, $task->only(['status', 'priority', 'assigned_to', 'due_date', 'due_at']), 'updated');

            if ((int) ($old['assigned_to'] ?? 0) !== (int) ($task->assigned_to ?? 0)) {
                $names = User::whereIn('id', array_filter([$old['assigned_to'], $task->assigned_to]))->pluck('name', 'id');
                $this->logActivity($task, 'Reassigned',
                    ($names[$old['assigned_to']] ?? 'Unassigned') . ' → ' . ($names[$task->assigned_to] ?? 'Unassigned'),
                    event: 'reassigned', meta: ['from' => $old['assigned_to'], 'to' => $task->assigned_to]);
            }

            if (($old['status'] ?? null) !== $task->status) {
                $this->logActivity($task, 'Status Changed', "{$old['status']} → {$task->status}",
                    event: 'status_changed', meta: ['from' => $old['status'], 'to' => $task->status]);
            }

            $oldDue = $old['due_at'] ?? null;
            if (($oldDue?->toIso8601String()) !== $task->due_at?->toIso8601String()) {
                $this->logActivity($task, 'Due Changed', 'Deadline moved',
                    event: 'due_changed', meta: ['from' => $oldDue?->toIso8601String(), 'to' => $task->due_at?->toIso8601String()]);
            }
            $this->logForClients($task, 'Updated', $old, $data);

            return $task->fresh(['assignedUser:id,name', 'clients:id,client_name', 'labels']);
        });

        // Only a genuine hand-off is worth an alert; saving an unrelated field
        // on a task someone already owns is not.
        if ($updated->assigned_to !== $previousAssignee) {
            $this->notifyAssignee($updated);
        }

        return $updated;
    }

    /**
     * Nobody assigns work to themselves: a task is asked of someone else, who
     * does it and hands it back for the asker to accept. Self-assigned work
     * would review itself.
     *
     * Saving a task that is already yours unchanged is not a new assignment,
     * so older tasks can still be edited.
     *
     * @throws ValidationException
     */
    private function refuseSelfAssignment(mixed $assignee, ?Task $task = null): void
    {
        $me = Auth::id();

        if (!$assignee || !$me || (int) $assignee !== (int) $me) {
            return;
        }
        if ($task && (int) $task->assigned_to === (int) $me) {
            return;
        }

        throw ValidationException::withMessages([
            'assigned_to' => 'You can\'t assign a task to yourself. Choose who should do it.',
        ]);
    }

    /**
     * Alert whoever now owns the task. Assigning work to yourself is not news,
     * which is the same rule the workflow notifications follow.
     */
    private function notifyAssignee(Task $task): void
    {
        if (!$task->assigned_to || $task->assigned_to === Auth::id()) {
            return;
        }

        $assignee = User::find($task->assigned_to);

        if ($assignee) {
            $assignee->notify(new TaskAssigned($task));
        }
    }

    /**
     * The assignee moves their own task between working statuses.
     *
     * Only the status changes. Nothing else on the task is touched, which is
     * what separates this from a full update — starting work should not be a
     * back door into editing the brief.
     *
     * Silently no-ops when the status is already what was asked for, so a
     * double-click does not litter the activity feed.
     */
    public function changeWorkingStatus(Task $task, User $actor, string $status): Task
    {
        if (!in_array($status, Task::$workingStatuses, true)) {
            throw new InvalidArgumentException("'{$status}' is not a status an assignee may set.");
        }

        if ($task->status === $status) {
            return $task;
        }

        $previous = $task->status;

        $task->update(['status' => $status, 'updated_by' => $actor->id]);

        $this->logActivity($task, 'Status Changed', "{$previous} → {$status}", event: 'status_changed', meta: ['from' => $previous, 'to' => $status]);
        $this->logForClients($task, 'Status Changed', $previous, $status);

        return $task->fresh(['assignedUser:id,name', 'clients:id,client_name', 'labels']);
    }

    /**
     * The assignee hands the task back to whoever asked for it.
     *
     * Deliberately not "Completed": the person who requested the work decides
     * whether it is finished, so this parks it in their review queue instead.
     */
    /**
     * Files handed in with the submission are attached first, as ordinary
     * attachments, so they show in the task's files and count as the work they
     * are. Everything is checked against the locked row: a double click or a
     * second tab gets a clear refusal, not a second submission.
     *
     * Files can also arrive ahead of the hand-in — the submit dialog uploads
     * them one per request, so a batch never meets the server's size limit for
     * a single request. Their ids are passed as $attachmentIds and recorded on
     * the submission, but only those this person added to this task.
     *
     * @param  array<int,UploadedFile>  $files
     * @param  array<int,int|string>    $attachmentIds
     */
    public function submitForReview(Task $task, User $actor, ?string $note = null, array $files = [], array $attachmentIds = []): Task
    {
        $stored = [];

        try {
            DB::transaction(function () use ($task, $actor, $note, $files, $attachmentIds, &$stored) {
                $locked = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();

                // Same rule as the policy, against the row as it is now.
                if ($reason = $locked->submitBlocker()) {
                    throw ValidationException::withMessages(['status' => $reason]);
                }

                if ($locked->requires_attachment && !$files && !$this->hasSubmissionFile($locked)) {
                    throw ValidationException::withMessages([
                        'files' => 'This task needs a file with the submission. Attach your work, then submit.',
                    ]);
                }

                foreach ($files as $file) {
                    $stored[] = $this->uploadAttachment($locked, $file);
                }

                $handedIn = $attachmentIds
                    ? TaskAttachment::where('task_id', $locked->id)
                        ->where('user_id', $actor->id)
                        ->whereIn('id', array_map('intval', $attachmentIds))
                        ->pluck('id')->all()
                    : [];

                $locked->update([
                    'status'       => Task::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'updated_by'   => $actor->id,
                ]);

                $description = 'Submitted for review' . ($note ? ": {$note}" : '');
                $this->logActivity($locked, 'Submitted', $description, event: 'submitted', meta: array_filter([
                    'note'           => $note,
                    'attachment_ids' => array_values(array_unique([...array_map(fn (TaskAttachment $a) => $a->id, $stored), ...$handedIn])),
                ]));
                $this->logForClients($locked, 'Submitted', null, ['title' => $locked->title]);
            });
        } catch (\Throwable $e) {
            // The rows rolled back; the files already written must not linger
            // where nothing points to them.
            foreach ($stored as $attachment) {
                Storage::disk($attachment->disk ?: 'local')->delete($attachment->file_path);
            }
            throw $e;
        }

        $task = $task->fresh(['assignedUser:id,name', 'clients:id,client_name', 'labels']);
        $this->notifyReviewer($task, $actor, $note);

        return $task;
    }

    /**
     * Whether the work handed in includes a file: one added since the task was
     * last sent back (rework needs the reworked file), by someone doing the work
     * rather than the person who asked for it — a brief attached at creation is
     * not a deliverable.
     */
    public function hasSubmissionFile(Task $task): bool
    {
        $since = TaskRevision::where('task_id', $task->id)->max('created_at');

        return TaskAttachment::where('task_id', $task->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->where(fn ($q) => $q
                ->where('user_id', '!=', (int) $task->created_by)
                ->orWhere('user_id', (int) $task->assigned_to))
            ->exists();
    }

    /**
     * The reviewer accepts the work, or sends it back with a reason.
     *
     * Returning it reuses the revision record, so a rejected submission shows up
     * in the same history — and the same quality KPI — as any other rework.
     */
    public function review(Task $task, User $actor, bool $accept, array $data = []): Task
    {
        if ($accept) {
            $task->update([
                'status'          => 'Completed',
                'completion_date' => now()->toDateString(),
                'updated_by'      => $actor->id,
            ]);

            $this->logActivity($task, 'Approved', 'Submission accepted' . (!empty($data['note']) ? ": {$data['note']}" : ''),
                event: 'approved', meta: array_filter(['note' => $data['note'] ?? null, 'to' => 'Completed']));
            $this->logForClients($task, 'Submission Accepted', null, ['title' => $task->title]);
        } else {
            $this->requestRevision($task, [
                'reason_category' => $data['reason_category'] ?? 'Employee Mistake',
                'note'            => $data['note'] ?? null,
            ]);
        }

        $task = $task->fresh(['assignedUser:id,name', 'clients:id,client_name', 'labels']);
        $this->notifySubmitter($task, $actor, $accept, $data['note'] ?? null);

        return $task;
    }

    /** Tell the requester their work is waiting. */
    private function notifyReviewer(Task $task, User $actor, ?string $note): void
    {
        if (!$task->created_by || $task->created_by === $actor->id) {
            return;
        }

        $reviewer = User::find($task->created_by);
        $reviewer?->notify(new TaskSubmitted($task, $actor, $note));
    }

    /** Tell the assignee what the verdict was. */
    private function notifySubmitter(Task $task, User $actor, bool $accepted, ?string $note): void
    {
        if (!$task->assigned_to || $task->assigned_to === $actor->id) {
            return;
        }

        $assignee = User::find($task->assigned_to);
        $assignee?->notify(new TaskReviewed($task, $actor, $accepted, $note));
    }

    /**
     * Record a revision request against a task. Reopens a completed or submitted
     * task so the assignee can rework it. Only 'Employee Mistake' revisions count
     * against the quality KPI (see PerformanceCalculationService::revisionRate).
     */
    public function requestRevision(Task $task, array $data): TaskRevision
    {
        return DB::transaction(function () use ($task, $data) {
            $revision = TaskRevision::create([
                'task_id'         => $task->id,
                'requested_by'    => Auth::id(),
                'reason_category' => $data['reason_category'],
                'note'            => $data['note'] ?? null,
                'previous_status' => $task->status,
            ]);

            // Submitted work that is sent back reopens the same way completed
            // work does — it goes to the assignee, not into limbo.
            if (in_array($task->status, ['Completed', Task::STATUS_SUBMITTED], true)) {
                $task->update([
                    'status'          => 'In Progress',
                    'completion_date' => null,
                    'submitted_at'    => null,
                    'updated_by'      => Auth::id(),
                ]);
            }

            $description = "Revision requested ({$data['reason_category']})" . (!empty($data['note']) ? ": {$data['note']}" : '');
            $this->logActivity($task, 'Revision Requested', $description, event: 'returned', meta: array_filter([
                'reason_category' => $data['reason_category'],
                'note'            => $data['note'] ?? null,
            ]));
            $this->logForClients($task, 'Revision Requested', null, ['reason_category' => $data['reason_category']]);

            return $revision->load('requestedBy:id,name');
        });
    }

    public function delete(Task $task): void
    {
        DB::transaction(function () use ($task) {
            foreach ($task->attachments as $attachment) {
                Storage::disk($attachment->disk ?: 'local')->delete($attachment->file_path);
            }
            $this->logForClients($task, 'Deleted', ['title' => $task->title]);
            $task->delete();
        });
    }

    public function addComment(Task $task, string $comment): TaskComment
    {
        $created = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => Auth::id(),
            'comment' => $comment,
        ]);

        $this->logActivity($task, 'Comment Added', $comment, event: 'comment', meta: ['comment_id' => $created->id]);

        return $created->load('user:id,name');
    }

    public function deleteComment(TaskComment $comment): void
    {
        $comment->delete();
    }

    public function uploadAttachment(Task $task, UploadedFile $file): TaskAttachment
    {
        return DB::transaction(function () use ($task, $file) {
            $extension  = strtolower($file->getClientOriginalExtension());
            $storedName = Str::uuid() . ($extension !== '' ? '.' . $extension : '');
            // Parked on this server and moved to the provider in the background
            // when there is one — see UploadStaging.
            [$path, $disk] = $this->uploads->store($file, 'task-attachments/' . $task->id, $storedName);

            // storeAs() answers false rather than throwing when the provider
            // refuses the write. Saving the record anyway left an attachment
            // that listed fine and could never be downloaded.
            if (!$path) {
                throw ValidationException::withMessages([
                    'file' => 'The file could not be stored. Please try again, or ask an admin to check Settings → Storage & CDN.',
                ]);
            }

            $attachment = TaskAttachment::create([
                'task_id'       => $task->id,
                'user_id'       => Auth::id(),
                'original_name' => $file->getClientOriginalName(),
                'stored_name'   => $storedName,
                'file_path'     => $path,
                'disk'          => $disk,
                'mime_type'     => $file->getMimeType(),
                'file_size'     => $file->getSize(),
            ]);

            $this->logActivity($task, 'Attachment Added', $file->getClientOriginalName(), event: 'attachment_added', meta: [
                'attachment_id' => $attachment->id,
                'mime'          => $attachment->mime_type,
                'size'          => $attachment->file_size,
            ]);
            $this->uploads->pushLater($attachment);

            return $attachment->load('user:id,name');
        });
    }

    public function deleteAttachment(TaskAttachment $attachment): void
    {
        Storage::disk($attachment->disk ?: 'local')->delete($attachment->file_path);
        $this->logActivity($attachment->task, 'Attachment Removed', $attachment->original_name, event: 'attachment_removed', meta: ['attachment_id' => $attachment->id]);
        $attachment->delete();
    }

    /** A link or a note left beside the files. */
    public function addNote(Task $task, string $body): TaskNote
    {
        $note = TaskNote::create([
            'task_id' => $task->id,
            'user_id' => Auth::id(),
            'body'    => $body,
        ]);

        $this->logActivity($task, $note->is_link ? 'Link Shared' : 'Note Added', $body, event: 'note_added', meta: [
            'note_id' => $note->id,
            'kind'    => $note->is_link ? 'link' : 'note',
        ]);

        return $note->load('user:id,name');
    }

    public function deleteNote(TaskNote $note): void
    {
        $this->logActivity($note->task, $note->is_link ? 'Link Removed' : 'Note Removed', $note->body, event: 'note_removed', meta: [
            'note_id' => $note->id,
            'kind'    => $note->is_link ? 'link' : 'note',
        ]);
        $note->delete();
    }

    /**
     * Turn what the form sent into one deadline.
     *
     * An exact moment (due_at, sent with the browser's UTC offset) wins and the
     * model derives its day. A date on its own means the end of that day. The
     * day column is never written directly here, so the two cannot disagree —
     * and clearing a time by sending only a date really does clear it.
     */
    private function applyDeadline(array $data): array
    {
        if (!empty($data['due_at'])) {
            $data['due_at'] = Carbon::parse($data['due_at'])->setTimezone(config('app.timezone'));
            unset($data['due_date']);
        } elseif (array_key_exists('due_date', $data)) {
            $data['due_at'] = $data['due_date'] ? Carbon::parse($data['due_date'])->setTime(23, 59, 59) : null;
            unset($data['due_date']);
        } else {
            unset($data['due_at']);
        }

        return $data;
    }

    /**
     * Apply capacity-aware assignment rules — both are no-ops unless the matching
     * PerformanceSetting flag is enabled (defaults are off, so existing behaviour
     * is unchanged). Auto-assign fills an empty assignee with the least-loaded
     * employee; strict-limit blocks assigning to an already-overloaded one.
     */
    private function applyWorkloadRules(array $data): array
    {
        $settings = PerformanceSetting::current();

        if ($settings->auto_assign_enabled && empty($data['assigned_to'])) {
            // Never suggests the creator — nobody is assigned their own task.
            $assignee = $this->workload->suggestAssignee(
                User::with('capacity')->where('is_active', true)->whereKeyNot((int) Auth::id())->get()
            );
            if ($assignee) {
                $data['assigned_to'] = $assignee->id;
            }
        }

        if ($settings->strict_workload_limit && !empty($data['assigned_to'])) {
            $user = User::with('capacity')->find($data['assigned_to']);
            if ($user && $this->workload->isOverloaded($user)) {
                throw new WorkloadLimitException("{$user->name} is already overloaded. Reassign the task or raise their capacity before adding more work.");
            }
        }

        return $data;
    }

    /**
     * Record something that happened, then refresh who was involved.
     *
     * Involvement is rebuilt from this log rather than nudged incrementally, so
     * it can never drift from the evidence it is based on.
     */
    private function logActivity(
        Task $task,
        string $action,
        ?string $description = null,
        mixed $old = null,
        mixed $new = null,
        ?string $event = null,
        array $meta = [],
    ): void {
        \App\Models\TaskActivity::create([
            'task_id'     => $task->id,
            'user_id'     => Auth::id(),
            'action'      => $action,
            'event'       => $event,
            'meta'        => $meta ?: null,
            'description' => $description,
            'old_value'   => is_array($old) ? json_encode($old) : $old,
            'new_value'   => is_array($new) ? json_encode($new) : $new,
        ]);

        $this->involvement->rebuild($task->fresh() ?? $task);
    }

    /**
     * Record a global activity-log entry for a task-related action, once per
     * client the task is currently associated with — so the action shows up
     * on each of their Activity tabs, same as when a task could only ever
     * have one client. An internal task (no clients) still gets one entry,
     * just with no client tied to it.
     */
    private function logForClients(Task $task, string $action, mixed $old = null, mixed $new = null): void
    {
        $clientIds = $task->clients()->pluck('clients.id');

        if ($clientIds->isEmpty()) {
            $this->activityLog->log('Task', $action, null, $old, $new);

            return;
        }

        foreach ($clientIds as $clientId) {
            $this->activityLog->log('Task', $action, $clientId, $old, $new);
        }
    }
}
