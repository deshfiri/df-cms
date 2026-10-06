<?php

namespace App\Notifications;

use App\Models\ContentItem;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsInstantlyToDashboard;
use Illuminate\Notifications\Notification;

/**
 * SMM (or Marketing/Manager reviewing on their behalf) sent a checklist
 * item back before publishing it — mirrors TaskRevisionRequested. Goes to
 * whoever submitted the version that got sent back.
 */
class ChecklistRevisionRequested extends Notification
{
    use BroadcastsInstantlyToDashboard;

    public function __construct(
        private readonly ContentItem $item,
        private readonly User $requestedBy,
        private readonly ?string $note = null,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Content sent back for revision',
            'message' => "{$this->requestedBy->name} sent \"{$this->item->title}\" ({$this->item->brand->name}) back for revision"
                .($this->note ? " — {$this->note}" : ''),
            'url' => $this->item->category === ContentItem::CATEGORY_POSTER
                ? route('panels.designer')
                : route('panels.raw-content'),
        ];
    }
}
