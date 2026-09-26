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
use App\Notifications\TaskCommentMention;
use App\Notifications\TaskPartiallySubmitted;
use App\Notifications\TaskReviewed;
use App\Notifications\TaskRevisionRequested;
use App\Notifications\TaskSubmitted;
use App\Services\Storage\UploadStaging;
use App\Support\MentionParser;
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
            $this->refuseSelfAssignment($data['assignee_ids'] ?? []);
            $assigneeIds = $data['assignee_ids'] ?? [];
            unset($data['assignee_ids']);
            $data['created_by'] = Auth::id();
            $task = Task::create($data);

            if ($labelIds) {
                $task->labels()->sync($labelIds);
            }
            if ($clientIds) {
                $task->clients()->sync($clientIds);
            }
            if ($assigneeIds) {
                $task->assignees()->sync($assigneeIds);
            }

            $this->logActivity($task, 'Created', "Task \"{$task->title}\" created", event: 'created', meta: [
                'assignee_ids' => $assigneeIds,
                'due_at'       => $task->due_at?->toIso8601String(),
            ]);
            $this->logForClients($task, 'Created', null, ['title' => $task->title]);

            return $task->load('assignees:id,name', 'clients:id,client_name', 'labels');
        });

        // After commit: a notification for a task that rolled back would be a lie,
        // and the broadcast leaves the process immediately.
        $this->notifyAssignees($task, $task->assignees);

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
        $previousAssigneeIds = $task->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        if (array_key_exists('assignee_ids', $data)) {
            $this->refuseSelfAssignment($data['assignee_ids'] ?? [], $previousAssigneeIds);
        }

        $updated = DB::transaction(function () use ($task, $data, $previousAssigneeIds) {
            $labelIds = $data['label_ids'] ?? null;
            unset($data['label_ids']);
            $clientIds = array_key_exists('client_ids', $data) ? $data['client_ids'] : null;
            unset($data['client_ids']);
            $assigneeIds = array_key_exists('assignee_ids', $data) ? ($data['assignee_ids'] ?? []) : null;
            unset($data['assignee_ids']);
            $data = $this->applyDeadline($data);

            $old = $task->only(['status', 'priority', 'due_date', 'due_at']);
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
            if ($assigneeIds !== null) {
                $task->assignees()->sync($assigneeIds);
            }

            // The full before/after stays on one "Updated" row (deadline-extension
            // history reads it); the changes that matter to who did what are also
            // logged as their own events.
            $this->logActivity($task, 'Updated', 'Task updated', $old, $task->only(['status', 'priority', 'due_date', 'due_at']), 'updated');

            if ($assigneeIds !== null) {
                $newIds   = array_values(array_unique(array_map('intval', $assigneeIds)));
                $added    = array_values(array_diff($newIds, $previousAssigneeIds));
                $removed  = array_values(array_diff($previousAssigneeIds, $newIds));

                if ($added || $removed) {
                    $names    = User::whereIn('id', array_unique([...$previousAssigneeIds, ...$newIds]))->pluck('name', 'id');
                    $describe = fn (array $ids) => $ids ? collect($ids)->map(fn ($id) => $names[$id] ?? 'Unknown')->join(', ') : 'Unassigned';

                    $this->logActivity($task, 'Reassigned',
                        $describe($previousAssigneeIds) . ' → ' . $describe($newIds),
                        event: 'reassigned', meta: ['added' => $added, 'removed' => $removed]);
                }
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

            return $task->fresh(['assignees:id,name', 'clients:id,client_name', 'labels']);
        });

        // Only a genuine hand-off is worth an alert; saving an unrelated field
        // on a task someone already owns is not.
        $newlyAssigned = $updated->assignees->filter(fn ($u) => !in_array((int) $u->id, $previousAssigneeIds, true));
        if ($newlyAssigned->isNotEmpty()) {
            $this->notifyAssignees($updated, $newlyAssigned);
        }

        return $updated;
    }

    /**
     * Nobody assigns work to themselves: a task is asked of someone else, who
     * does it and hands it back for the asker to accept. Self-assigned work
     * would review itself.
     *
     * Saving a task that already includes you, unchanged, is not a new
     * assignment, so older tasks can still be edited — checked against the
     * *previous* set of assignees, not the new one, so adding yourself
     * alongside people who were already there still refuses.
     *
     * @param  array<int,mixed>  $assigneeIds
     * @param  array<int,int>    $previousAssigneeIds
     * @throws ValidationException
     */
    private function refuseSelfAssignment(array $assigneeIds, array $previousAssigneeIds = []): void
    {
        $me = Auth::id();

        if (!$me) {
            return;
        }

        $ids = array_map('intval', $assigneeIds);

        if (in_array((int) $me, $ids, true) && !in_array((int) $me, $previousAssigneeIds, true)) {
            throw ValidationException::withMessages([
                'assignee_ids' => 'You can\'t assign a task to yourself. Choose who should do it.',
            ]);
        }
    }

    /**
     * Alert whoever now owns the task. Assigning work to yourself is not news,
     * which is the same rule the workflow notifications follow.
     *
     * @param  iterable<User>  $assignees
     */
    private function notifyAssignees(Task $task, iterable $assignees): void
    {
        foreach ($assignees as $assignee) {
            if ((int) $assignee->id === (int) Auth::id()) {
                continue;
            }
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

        return $task->fresh(['assignees:id,name', 'clients:id,client_name', 'labels']);
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
    /**
     * On a solo task, this is the whole thing: one submission, straight to
     * Submitted. On a shared one, this is only this assignee's own part —
     * the task sits at Task::STATUS_PARTIALLY_SUBMITTED, and the reviewer
     * hears nothing, until every current assignee has done the same (see
     * TaskInvolvementService for how the credit for each submission splits).
     */
    public function submitForReview(Task $task, User $actor, ?string $note = null, array $files = [], array $attachmentIds = []): Task
    {
        $stored = [];
        $fullySubmitted = false;

        try {
            DB::transaction(function () use ($task, $actor, $note, $files, $attachmentIds, &$stored, &$fullySubmitted) {
                $locked = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();

                // Same rule as the policy, against the row as it is now.
                if ($reason = $locked->submitBlocker($actor)) {
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

                // This assignee's own part. Whoever else is still assigned and
                // has not yet submitted theirs is what decides where the task
                // as a whole lands — never headcount, so an assignee who was
                // removed after already submitting cannot hold the rest up.
                $locked->assignees()->updateExistingPivot($actor->id, ['submitted_at' => now()]);
                $assigneeIds = $locked->assignees()->pluck('users.id');
                $stillWaiting = DB::table('task_user')
                    ->where('task_id', $locked->id)
                    ->whereIn('user_id', $assigneeIds)
                    ->whereNull('submitted_at')
                    ->exists();
                $fullySubmitted = !$stillWaiting;

                $locked->update([
                    'status'       => $fullySubmitted ? Task::STATUS_SUBMITTED : Task::STATUS_PARTIALLY_SUBMITTED,
                    'submitted_at' => $fullySubmitted ? now() : $locked->submitted_at,
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

        $task = $task->fresh(['assignees:id,name', 'clients:id,client_name', 'labels']);

        if ($fullySubmitted) {
            $this->notifyReviewer($task, $actor, $note);
        } else {
            $this->notifyRemainingAssignees($task, $actor);
        }

        return $task;
    }

    /** Nudge whoever on a shared task still has not submitted their own part. */
    private function notifyRemainingAssignees(Task $task, User $submittedBy): void
    {
        foreach ($task->assignees as $assignee) {
            if ((int) $assignee->id === (int) $submittedBy->id || $assignee->pivot->submitted_at !== null) {
                continue;
            }
            $assignee->notify(new TaskPartiallySubmitted($task, $submittedBy));
        }
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

        $assigneeIds = $task->assignees()->pluck('users.id')->all();

        return TaskAttachment::where('task_id', $task->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->where(fn ($q) => $q
                ->where('user_id', '!=', (int) $task->created_by)
                ->orWhereIn('user_id', $assigneeIds))
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

        $task = $task->fresh(['assignees:id,name', 'clients:id,client_name', 'labels']);
        $this->notifyReviewVerdict($task, $actor, $accept, $data['note'] ?? null);

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

    /** Tell every assignee what the verdict was. */
    private function notifyReviewVerdict(Task $task, User $actor, bool $accepted, ?string $note): void
    {
        foreach ($task->assignees as $assignee) {
            if ((int) $assignee->id === (int) $actor->id) {
                continue;
            }
            $assignee->notify(new TaskReviewed($task, $actor, $accepted, $note));
        }
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

            // Submitted (whole or partial) or completed work that is sent back
            // reopens the same way — it goes to the assignees, not into limbo.
            // Every current assignee's own submitted_at resets too: on a
            // shared task, whoever had already submitted their part submits
            // it again against the corrected brief, same as whoever had not.
            if (in_array($task->status, ['Completed', Task::STATUS_SUBMITTED, Task::STATUS_PARTIALLY_SUBMITTED], true)) {
                $task->update([
                    'status'          => 'In Progress',
                    'completion_date' => null,
                    'submitted_at'    => null,
                    'updated_by'      => Auth::id(),
                ]);
                DB::table('task_user')->where('task_id', $task->id)->update(['submitted_at' => null]);
            }

            $description = "Revision requested ({$data['reason_category']})" . (!empty($data['note']) ? ": {$data['note']}" : '');
            $this->logActivity($task, 'Revision Requested', $description, event: 'returned', meta: array_filter([
                'reason_category' => $data['reason_category'],
                'note'            => $data['note'] ?? null,
            ]));
            $this->logForClients($task, 'Revision Requested', null, ['reason_category' => $data['reason_category']]);

            $this->notifyRevisionRequested($task, $data);

            return $revision->load('requestedBy:id,name');
        });
    }

    /**
     * Tell whoever created the task that an assignee sent it back — the
     * mirror of notifyReviewVerdict()'s assignee-facing notice. When the
     * creator is the one requesting the revision (rejecting a submission
     * via review(), or sending it back directly), they already know, so
     * nothing fires here; review() notifies the assignees itself.
     */
    private function notifyRevisionRequested(Task $task, array $data): void
    {
        $actorId = Auth::id();
        if (!$task->created_by || (int) $task->created_by === (int) $actorId) {
            return;
        }

        $actor = Auth::user();
        $creator = User::find($task->created_by);
        $creator?->notify(new TaskRevisionRequested($task, $actor, $data['reason_category'], $data['note'] ?? null));
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
        $this->notifyMentions($task, $comment);

        return $created->load('user:id,name');
    }

    /**
     * A task's discussion can only @mention the people actually party to
     * it — its assignees and whoever created it — not anyone in the system.
     * Only those who were named get notified; commenting itself notifies
     * no one.
     */
    private function notifyMentions(Task $task, string $comment): void
    {
        $task->loadMissing(['assignees:id,name', 'createdBy:id,name']);
        $candidates = $task->assignees->push($task->createdBy)->filter()->unique('id');

        $author = Auth::user();
        $mentioned = MentionParser::extract($comment, $candidates)
            ->reject(fn (User $u) => $author && $u->id === $author->id);

        foreach ($mentioned as $user) {
            $user->notify(new TaskCommentMention($task, $author, $comment));
        }
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
     * is unchanged). Auto-assign fills an empty assignee list with one
     * least-loaded employee; strict-limit blocks assigning to anyone already
     * overloaded, whoever else is on the task with them.
     */
    private function applyWorkloadRules(array $data): array
    {
        $settings = PerformanceSetting::current();

        if ($settings->auto_assign_enabled && empty($data['assignee_ids'])) {
            // Never suggests the creator — nobody is assigned their own task.
            $assignee = $this->workload->suggestAssignee(
                User::with('capacity')->where('is_active', true)->whereKeyNot((int) Auth::id())->get()
            );
            if ($assignee) {
                $data['assignee_ids'] = [$assignee->id];
            }
        }

        if ($settings->strict_workload_limit && !empty($data['assignee_ids'])) {
            $users = User::with('capacity')->whereIn('id', $data['assignee_ids'])->get();
            foreach ($users as $user) {
                if ($this->workload->isOverloaded($user)) {
                    throw new WorkloadLimitException("{$user->name} is already overloaded. Reassign the task or raise their capacity before adding more work.");
                }
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
