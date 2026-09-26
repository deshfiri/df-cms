<?php

namespace App\Notifications;

use App\Models\FlowItem;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Someone @mentioned this person in a workflow item's discussion. */
class FlowItemCommentMention extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly FlowItem $item,
        private readonly User $author,
        private readonly string $body,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => "{$this->author->name} mentioned you",
            'message' => "{$this->item->titleWithClient()}: " . Str::limit($this->body, 100),
            'url'     => route('flow-items.show', $this->item),
        ];
    }
}
