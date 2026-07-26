<?php

namespace App\Notifications\Portal;

use App\Models\ClientDocument;
use Illuminate\Notifications\Notification;

class DocumentUploaded extends Notification
{
    public function __construct(
        private readonly ClientDocument $document,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => 'New document shared',
            'message' => $this->document->title,
            'url'     => route('portal.documents.index'),
        ];
    }
}
