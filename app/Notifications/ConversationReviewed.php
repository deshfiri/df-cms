<?php

namespace App\Notifications;

use App\Models\SmmClientConversation;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsInstantlyToDashboard;
use Illuminate\Notifications\Notification;

/**
 * Sent only to the SMM user who submitted the conversation, with Marketing's
 * verdict. Nobody else is told about another user's record.
 */
class ConversationReviewed extends Notification
{
    use BroadcastsInstantlyToDashboard;

    public function __construct(
        private readonly SmmClientConversation $conversation,
        private readonly User $reviewer,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $approved = $this->conversation->review_status === SmmClientConversation::STATUS_APPROVED;

        return [
            'title' => $approved ? 'Conversation approved as a Potential Client' : 'Conversation not counted as a Potential Client',
            'message' => "{$this->reviewer->name} reviewed your conversation for {$this->conversation->brand->name}: ".($approved ? 'approved.' : 'not a Potential Client.'),
            'url' => route('panels.smm'),
        ];
    }
}
