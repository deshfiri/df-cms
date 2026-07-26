<?php

namespace App\Notifications\Portal;

use App\Models\ClientActionRequest;
use Illuminate\Notifications\Notification;

class ActionRequested extends Notification
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
        $isRevision = $this->actionRequest->status === ClientActionRequest::STATUS_NEED_REVISION;

        return [
            'title'   => $isRevision ? 'Revision needed' : 'Action requested',
            'message' => $isRevision
                ? "Please update your response for \"{$this->actionRequest->title}\"."
                : "Please respond to: \"{$this->actionRequest->title}\".",
            'url'     => route('portal.actions.show', $this->actionRequest),
        ];
    }
}
