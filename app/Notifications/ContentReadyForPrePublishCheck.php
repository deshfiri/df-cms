<?php

namespace App\Notifications;

use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsInstantlyToDashboard;
use Illuminate\Notifications\Notification;

/**
 * A content item (fresh submission or a resubmission after either Marketing's
 * or SMM's revision request) is now waiting on Marketing's pre-publish check
 * — the new first gate in the canonical flow: Content/Design -> Marketing
 * Pre-Publish Check -> SMM -> Marketing Post-Publish Review -> Complete.
 * Dispatched once per submit(), so a resubmission gets its own independent
 * notification rather than mutating whatever the first submission's said.
 *
 * Replaces the old ContentSubmissionReadyForCollection, which notified SMM
 * directly on submit — wrong under the new flow, where SMM may only collect
 * a version Marketing has explicitly approved (see
 * ContentApprovedForPublishing, dispatched from
 * ContentItemService::approveForHandover()).
 */
class ContentReadyForPrePublishCheck extends Notification
{
    use BroadcastsInstantlyToDashboard;

    private const CATEGORY_LABELS = [
        ContentItem::CATEGORY_RAW_CONTENT => 'Raw Content',
        ContentItem::CATEGORY_ADVERTISING_CONTENT => 'Advertising Content',
        ContentItem::CATEGORY_POSTER => 'Poster',
    ];

    public function __construct(
        private readonly ContentItem $item,
        private readonly ContentItemSubmission $submission,
        private readonly int $versionNumber,
        private readonly User $submittedBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $label = self::CATEGORY_LABELS[$this->item->category] ?? 'Content';

        return [
            'title' => "{$label} Ready for Pre-Publish Check",
            'message' => "V{$this->versionNumber} of \"{$this->item->title}\" for {$this->item->brand->name} is waiting on Marketing's pre-publish check.",
            'url' => route('panels.marketing'),
        ];
    }
}
