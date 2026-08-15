<?php

namespace App\Notifications;

use App\Models\FlowItem;
use Illuminate\Notifications\Notification;

/** Tells the item's creator that it has finished the final stage. */
class FlowItemCompleted extends Notification
{
    public function __construct(private readonly FlowItem $item) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title'   => 'Item completed',
            'message' => "\"{$this->item->title}\" finished the {$this->item->flow?->name} workflow.",
            'url'     => route('flow-items.show', $this->item),
        ];
    }
}
