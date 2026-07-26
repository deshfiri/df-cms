<?php

namespace App\Notifications\Portal;

use App\Models\ClientProjectUpdate;
use Illuminate\Notifications\Notification;

class ProjectUpdatePosted extends Notification
{
    public function __construct(
        private readonly ClientProjectUpdate $update,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => 'New project update',
            'message' => $this->update->title,
            'url'     => route('portal.updates.show', $this->update),
        ];
    }
}
