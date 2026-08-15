<?php

namespace App\Notifications;

use App\Models\FlowItem;
use Illuminate\Notifications\Notification;

/** Daily nudge to a stage's assignees that an item they hold is past its due date. */
class FlowItemOverdue extends Notification
{
    public function __construct(private readonly FlowItem $item) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        $days = $this->item->due_date ? (int) $this->item->due_date->startOfDay()->diffInDays(today()) : 0;

        return [
            'title'   => 'Overdue item',
            'message' => "\"{$this->item->title}\" at {$this->item->currentStage?->name} is {$days} day(s) overdue.",
            'url'     => route('flow-items.show', $this->item),
        ];
    }
}
