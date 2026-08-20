<?php

namespace App\Services;

use App\Exceptions\WorkloadLimitException;
use App\Models\PerformanceSetting;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskRevision;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskReviewed;
use App\Notifications\TaskSubmitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskService
{
    public function __construct(
        private readonly ActivityLogService    $activityLog,
        private readonly WorkloadService       $workload,
    ) {}

    public function create(array $data): Task
    {
        $task = DB::transaction(function () use ($data) {
            $labelIds = $data['label_ids'] ?? [];
            unset($data['label_ids']);

            $data = $this->applyWorkloadRules($data);
            $data['created_by'] = Auth::id();
            $task = Task::create($data);

            if ($labelIds) {
                $task->labels()->sync($labelIds);
            }

            $this->logActivity($task, 'Created', "Task \"{$task->title}\" created");
            $this->activityLog->log('Task', 'Created', $task->client_id, null, ['title' => $task->title]);

            return $task->load('assignedUser:id,name', 'client:id,client_name', 'labels');
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

        $updated = DB::transaction(function () use ($task, $data) {
            $labelIds = $data['label_ids'] ?? null;
            unset($data['label_ids']);

            $old = $task->only(['status', 'priority', 'assigned_to', 'due_date']);
            $data['updated_by'] = Auth::id();

            if (($data['status'] ?? null) === 'Completed' && $task->status !== 'Completed') {
                $data['completion_date'] = now()->toDateString();
            }

            $task->update($data);

            if ($labelIds !== null) {
                $task->labels()->sync($labelIds);
            }

            $this->logActivity($task, 'Updated', 'Task updated', $old, $task->only(['status', 'priority', 'assigned_to', 'due_date']));
            $this->activityLog->log('Task', 'Updated', $task->client_id, $old, $data);

            return $task->fresh(['assignedUser:id,name', 'client:id,client_name', 'labels']);
        });

        // Only a genuine hand-off is worth an alert; saving an unrelated field
        // on a task someone already owns is not.
        if ($updated->assigned_to !== $previousAssignee) {
            $this->notifyAssignee($updated);
        }

        return $updated;
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
     * The assignee hands the task back to whoever asked for it.
     *
     * Deliberately not "Completed": the person who requested the work decides
     * whether it is finished, so this parks it in their review queue instead.
     */
    public function submitForReview(Task $task, User $actor, ?string $note = null): Task
    {
        $task->update([
            'status'       => Task::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'updated_by'   => $actor->id,
        ]);

        $description = 'Submitted for review' . ($note ? ": {$note}" : '');
        $this->logActivity($task, 'Submitted', $description);
        $this->activityLog->log('Task', 'Submitted', $task->client_id, null, ['title' => $task->title]);

        $this->notifyReviewer($task, $actor, $note);

        return $task->fresh(['assignedUser:id,name', 'client:id,client_name', 'labels']);
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

            $this->logActivity($task, 'Approved', 'Submission accepted' . (!empty($data['note']) ? ": {$data['note']}" : ''));
            $this->activityLog->log('Task', 'Submission Accepted', $task->client_id, null, ['title' => $task->title]);
        } else {
            $this->requestRevision($task, [
                'reason_category' => $data['reason_category'] ?? 'Employee Mistake',
                'note'            => $data['note'] ?? null,
            ]);
        }

        $task = $task->fresh(['assignedUser:id,name', 'client:id,client_name', 'labels']);
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
            $this->logActivity($task, 'Revision Requested', $description);
            $this->activityLog->log('Task', 'Revision Requested', $task->client_id, null, ['reason_category' => $data['reason_category']]);

            return $revision->load('requestedBy:id,name');
        });
    }

    public function delete(Task $task): void
    {
        DB::transaction(function () use ($task) {
            foreach ($task->attachments as $attachment) {
                Storage::disk('local')->delete($attachment->file_path);
            }
            $this->activityLog->log('Task', 'Deleted', $task->client_id, ['title' => $task->title]);
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

        $this->logActivity($task, 'Comment Added', $comment);

        return $created->load('user:id,name');
    }

    public function deleteComment(TaskComment $comment): void
    {
        $comment->delete();
    }

    public function uploadAttachment(Task $task, UploadedFile $file): TaskAttachment
    {
        return DB::transaction(function () use ($task, $file) {
            $storedName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path       = $file->storeAs('task-attachments/' . $task->id, $storedName, 'local');

            $attachment = TaskAttachment::create([
                'task_id'       => $task->id,
                'user_id'       => Auth::id(),
                'original_name' => $file->getClientOriginalName(),
                'stored_name'   => $storedName,
                'file_path'     => $path,
                'mime_type'     => $file->getMimeType(),
                'file_size'     => $file->getSize(),
            ]);

            $this->logActivity($task, 'Attachment Added', $file->getClientOriginalName());

            return $attachment->load('user:id,name');
        });
    }

    public function deleteAttachment(TaskAttachment $attachment): void
    {
        Storage::disk('local')->delete($attachment->file_path);
        $this->logActivity($attachment->task, 'Attachment Removed', $attachment->original_name);
        $attachment->delete();
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
            $assignee = $this->workload->suggestAssignee(
                User::with('capacity')->where('is_active', true)->get()
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

    private function logActivity(Task $task, string $action, ?string $description = null, mixed $old = null, mixed $new = null): void
    {
        \App\Models\TaskActivity::create([
            'task_id'     => $task->id,
            'user_id'     => Auth::id(),
            'action'      => $action,
            'description' => $description,
            'old_value'   => is_array($old) ? json_encode($old) : $old,
            'new_value'   => is_array($new) ? json_encode($new) : $new,
        ]);
    }
}
