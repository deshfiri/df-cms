<?php

namespace App\Notifications;

use App\Models\FlowItem;
use App\Models\FlowStage;
use Illuminate\Notifications\Notification;

/**
 * Tells a stage's assigned users that a work item is now waiting on them —
 * sent when an item is created into, advanced into, or sent back to their
 * stage, or released by whoever had claimed it. Database (bell) + broadcast
 * (live badge via the personal channel).
 */
class FlowItemAwaitingYou extends Notification
{
    public function __construct(
        private readonly FlowItem $item,
        private readonly FlowStage $stage,
        private readonly string $reason,
        private readonly string $title = 'Work awaiting you',
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title'   => $this->title,
            'message' => "{$this->item->titleWithClient()} is at {$this->stage->name}" . ($this->reason ? " — {$this->reason}" : '') . '.',
            'url'     => route('flow-items.show', $this->item),
        ];
    }
}
