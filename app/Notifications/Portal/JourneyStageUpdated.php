<?php

namespace App\Notifications\Portal;

use App\Models\ClientStageProgress;
use Illuminate\Notifications\Notification;

class JourneyStageUpdated extends Notification
{
    public function __construct(
        private readonly ClientStageProgress $progress,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $stage = $this->progress->stage;

        return [
            'title'   => "Journey update: {$stage->name}",
            'message' => "\"{$stage->name}\" is now {$this->progress->status}.",
            'url'     => route('portal.journey'),
        ];
    }
}
