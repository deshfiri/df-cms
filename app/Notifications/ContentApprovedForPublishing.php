<?php

namespace App\Notifications;

use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsInstantlyToDashboard;
use Illuminate\Notifications\Notification;

/**
 * Marketing just passed this exact submission through pre-publish check and
 * handed it over — SMM may now collect it. Dispatched once per approval
 * (see ContentItemService::approveForHandover()), never for a submission
 * Marketing hasn't explicitly approved; a resubmission after any revision
 * gets its own independent approval and its own independent notification.
 */
class ContentApprovedForPublishing extends Notification
{
    use BroadcastsInstantlyToDashboard;

    public function __construct(
        private readonly ContentItem $item,
        private readonly ContentItemSubmission $submission,
        private readonly int $versionNumber,
        private readonly User $approvedBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Content Approved for Publishing',
            'message' => "V{$this->versionNumber} of \"{$this->item->title}\" for {$this->item->brand->name} was approved by Marketing and is ready for collection.",
            'url' => route('panels.smm'),
        ];
    }
}
