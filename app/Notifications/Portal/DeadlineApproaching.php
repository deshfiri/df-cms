<?php

namespace App\Notifications\Portal;

use App\Models\ClientActionRequest;
use Illuminate\Notifications\Notification;

class DeadlineApproaching extends Notification
{
    public function __construct(
        private readonly ClientActionRequest $actionRequest,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => 'Deadline approaching',
            'message' => "\"{$this->actionRequest->title}\" is due {$this->actionRequest->due_date->format('d M Y')}.",
            'url'     => route('portal.actions.show', $this->actionRequest),
        ];
    }
}
