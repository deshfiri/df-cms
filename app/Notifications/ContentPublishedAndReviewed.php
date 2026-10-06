<?php

namespace App\Notifications;

use App\Models\ContentItem;
use App\Models\PublishedContent;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * The one notification that means "this content item's current cycle is
 * fully done" — submitted, collected, published, AND reviewed. Only ever
 * dispatched for the submission that was still the item's latest at the
 * moment it was reviewed (see MarketingBillingController::
 * reviewPublishedContent()); a stale/historical review can never trigger
 * this for a version a revision has already superseded.
 */
class ContentPublishedAndReviewed extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly ContentItem $item,
        private readonly PublishedContent $published,
        private readonly int $versionNumber,
        private readonly User $reviewedBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Content Published & Reviewed',
            'message' => "V{$this->versionNumber} of \"{$this->item->title}\" for {$this->item->brand->name} has completed the publishing and review workflow.",
            'url' => route('marketing.checklist', $this->item->brand),
        ];
    }
}
