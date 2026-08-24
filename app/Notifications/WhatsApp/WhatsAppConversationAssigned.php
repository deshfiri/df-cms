<?php

namespace App\Notifications\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

/**
 * Someone handed this agent a WhatsApp conversation.
 *
 * Worth a notification in its own right: assignment is what grants an ordinary
 * agent access to the thread at all, so it is the moment work becomes theirs.
 */
class WhatsAppConversationAssigned extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly WhatsAppConversation $conversation,
        private readonly User $assignedBy,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $who   = $this->conversation->contact?->displayName() ?? 'A customer';
        $brand = $this->conversation->brand?->name;

        return [
            'title'     => 'WhatsApp conversation assigned to you',
            'message'   => "{$who}" . ($brand ? " ({$brand})" : '') . ' — assigned by ' . $this->assignedBy->name,
            'client_id' => null,
            'url'       => route('whatsapp.inbox', ['conversation' => $this->conversation->id]),
        ];
    }
}
