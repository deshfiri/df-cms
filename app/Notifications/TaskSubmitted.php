<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * The assignee has handed work back for review. Goes to whoever asked for it.
 */
class TaskSubmitted extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly Task $task,
        private readonly User $submittedBy,
        private readonly ?string $note = null,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'     => 'Task submitted for your review',
            'message'   => "{$this->submittedBy->name} submitted \"{$this->task->title}\""
                . ($this->note ? " — {$this->note}" : ''),
            // The task's page, where the Review button is.
            'url'       => route('tasks.show', $this->task->id),
        ];
    }
}
