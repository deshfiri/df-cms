<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * Tells a remaining assignee on a shared task that someone else has
 * submitted their part — the task itself isn't handed in yet (see
 * Task::STATUS_PARTIALLY_SUBMITTED); it's waiting on this person's part too.
 * Goes to every current assignee except whoever just submitted and anyone
 * who has already submitted their own part.
 */
class TaskPartiallySubmitted extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly Task $task,
        private readonly User $submittedBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => 'Your part is still needed',
            'message' => "{$this->submittedBy->name} submitted their part of \"{$this->task->title}\" — it isn't complete until you submit yours too.",
            'url'     => route('tasks.show', $this->task->id),
        ];
    }
}
