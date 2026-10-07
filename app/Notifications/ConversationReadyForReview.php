<?php

namespace App\Notifications;

use App\Models\SmmClientConversation;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsInstantlyToDashboard;
use Illuminate\Notifications\Notification;

/** A new SMM client conversation with evidence is waiting for Marketing's verification. */
class ConversationReadyForReview extends Notification
{
    use BroadcastsInstantlyToDashboard;

    public function __construct(
        private readonly SmmClientConversation $conversation,
        private readonly User $submittedBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Client conversation awaiting verification',
            'message' => "{$this->submittedBy->name} logged a client conversation for {$this->conversation->brand->name} for verification.",
            'url' => route('panels.marketing'),
        ];
    }
}
