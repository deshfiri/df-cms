<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskService
{
    public function __construct(
        private readonly ActivityLogService   $activityLog,
        private readonly ChangeApprovalService $changeApproval,
    ) {}

    public function create(array $data): Task
    {
        return DB::transaction(function () use ($data) {
            $labelIds = $data['label_ids'] ?? [];
            unset($data['label_ids']);

            $data['created_by'] = Auth::id();
            $task = Task::create($data);

            if ($labelIds) {
                $task->labels()->sync($labelIds);
            }

            $this->logActivity($task, 'Created', "Task \"{$task->title}\" created");
            $this->activityLog->log('Task', 'Created', $task->client_id, null, ['title' => $task->title]);

            return $task->load('assignedUser:id,name', 'client:id,client_name', 'labels');
        });
    }

    public function update(Task $task, array $data): Task
    {
        // Guard on the full requested payload (label_ids included) before it's
        // stripped below, so an approved change replays identically later.
        $this->changeApproval->guard(Task::class, $task->id, $task->only(array_keys($data)), $data, Auth::user());

        return DB::transaction(function () use ($task, $data) {
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
