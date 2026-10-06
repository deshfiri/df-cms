<?php

namespace App\Notifications;

use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * A content item (fresh submission or a resubmission after revision) is now
 * sitting at `available`, ready for SMM to collect — mirrors
 * ChecklistRevisionRequested's shape, the other handoff notification this
 * workflow already had. Dispatched once per submit(), so a resubmission
 * after revision gets its own independent notification rather than mutating
 * whatever the first submission's notification said.
 */
class ContentSubmissionReadyForCollection extends Notification
{
    use BroadcastsToDashboard;

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
            'title' => "New {$label} Ready",
            'message' => "V{$this->versionNumber} of \"{$this->item->title}\" for {$this->item->brand->name} is ready for collection.",
            'url' => route('panels.smm'),
        ];
    }
}
