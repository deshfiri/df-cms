<?php

namespace App\Notifications;

use App\Models\ContentItem;
use App\Models\PublishedContent;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsInstantlyToDashboard;
use Illuminate\Notifications\Notification;

/**
 * SMM just published one specific submission — Marketing (holders of
 * `manage publishing-review`) need to know it's waiting in their queue.
 * Publishing alone is not workflow completion; see
 * ContentPublishedAndReviewed for the separate, later Manager notification
 * that only fires once Marketing has actually reviewed it.
 */
class ContentReadyForPublishingReview extends Notification
{
    use BroadcastsInstantlyToDashboard;

    public function __construct(
        private readonly ContentItem $item,
        private readonly PublishedContent $published,
        private readonly int $versionNumber,
        private readonly User $publishedBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Content Ready for Review',
            'message' => "V{$this->versionNumber} of \"{$this->item->title}\" for {$this->item->brand->name} was published and is ready for review.",
            'url' => route('marketing.brand', $this->item->brand),
        ];
    }
}
