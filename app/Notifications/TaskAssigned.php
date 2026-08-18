<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * Tells someone a task is now theirs — on creation with an assignee, or when an
 * existing task is reassigned to them. Database (bell) + broadcast (live), so it
 * lands on their dashboard without waiting for the poll.
 */
class TaskAssigned extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(private readonly Task $task) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $due = $this->task->due_date
            ? ' — due ' . $this->task->due_date->format('d M Y')
            : '';

        return [
            'title'     => 'Task assigned to you',
            'message'   => "\"{$this->task->title}\" ({$this->task->priority}){$due}",
            'client_id' => $this->task->client_id,
            'url'       => route('tasks.show', $this->task),
        ];
    }
}
