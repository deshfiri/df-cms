<?php

namespace App\Notifications;

use App\Models\FlowItem;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Tells participants that someone commented on a workflow item. */
class FlowItemNewComment extends Notification
{
    public function __construct(
        private readonly FlowItem $item,
        private readonly User $author,
        private readonly string $body,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title'   => "New comment · {$this->author->name}",
            'message' => "\"{$this->item->title}\": " . Str::limit($this->body, 90),
            'url'     => route('flow-items.show', $this->item),
        ];
    }
}
