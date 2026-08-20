<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * The reviewer accepted the work, or sent it back. Goes to the assignee.
 */
class TaskReviewed extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly Task $task,
        private readonly User $reviewedBy,
        private readonly bool $accepted,
        private readonly ?string $note = null,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $verdict = $this->accepted ? 'accepted' : 'sent back for changes';

        return [
            'title'     => $this->accepted ? 'Task accepted' : 'Task sent back',
            'message'   => "{$this->reviewedBy->name} {$verdict}: \"{$this->task->title}\""
                . ($this->note ? " — {$this->note}" : ''),
            'client_id' => $this->task->client_id,
            'url'       => route('tasks.index'),
        ];
    }
}
