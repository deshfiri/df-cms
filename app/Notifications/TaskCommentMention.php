<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Someone @mentioned this person in a task's discussion. */
class TaskCommentMention extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly Task $task,
        private readonly User $author,
        private readonly string $comment,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => "{$this->author->name} mentioned you",
            'message' => "\"{$this->task->title}\": " . Str::limit($this->comment, 100),
            'url'     => route('tasks.show', $this->task->id),
        ];
    }
}
