<?php

namespace App\Notifications;

use App\Models\ContentItem;
use App\Models\ContentItemStageOwner;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsInstantlyToDashboard;
use Illuminate\Notifications\Notification;

/**
 * Sent only to the one user a stage was directly assigned to. Other users in
 * the same role get nothing, so they never look like owners of work that
 * belongs to someone else. Delivered through the existing database + Reverb
 * channel.
 */
class StageAssignedToYou extends Notification
{
    use BroadcastsInstantlyToDashboard;

    private const STAGE_LABELS = [
        ContentItemStageOwner::STAGE_MAKER => 'revision to fix',
        ContentItemStageOwner::STAGE_PRE_PUBLISH => 'pre-publish check',
        ContentItemStageOwner::STAGE_PUBLISH => 'publishing',
        ContentItemStageOwner::STAGE_FINAL_REVIEW => 'final review',
    ];

    public function __construct(
        private readonly ContentItem $item,
        private readonly string $stage,
        private readonly User $assignedBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $what = self::STAGE_LABELS[$this->stage] ?? 'work';

        return [
            'title' => 'Assigned to you: '.ucfirst($what),
            'message' => "{$this->assignedBy->name} assigned you the {$what} for \"{$this->item->title}\" ({$this->item->brand->name}).",
            'url' => route('panels.marketing'),
        ];
    }
}
