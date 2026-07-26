<?php

namespace App\Notifications\Portal;

use App\Models\WorkflowStage;
use Illuminate\Notifications\Notification;

class ServiceCompleted extends Notification
{
    public function __construct(
        private readonly WorkflowStage $stage,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => "Service completed: {$this->stage->department}",
            'message' => "All stages under {$this->stage->department} are now complete.",
            'url'     => route('portal.services.index'),
        ];
    }
}
