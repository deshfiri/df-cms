<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * An assignee sent the task back before (or instead of) doing the work,
 * flagging a problem with the brief itself. Goes to whoever created it.
 */
class TaskRevisionRequested extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly Task $task,
        private readonly User $requestedBy,
        private readonly string $reasonCategory,
        private readonly ?string $note = null,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'     => 'Task sent back to you',
            'message'   => "{$this->requestedBy->name} sent \"{$this->task->title}\" back ({$this->reasonCategory})"
                . ($this->note ? " — {$this->note}" : ''),
            // Straight to the task, so the note and the fix can happen from there.
            'url'       => route('tasks.show', $this->task->id),
        ];
    }
}
