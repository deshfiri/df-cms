<?php

namespace App\Notifications\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A customer replied on a conversation this agent owns.
 *
 * Worded so it is unmistakably *not* the internal chat (spec §33): the title
 * names WhatsApp and the brand, so a glance at the bell tells someone which of
 * the two systems wants them.
 */
class WhatsAppMessageArrived extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly WhatsAppConversation $conversation,
        private readonly WhatsAppMessage $message,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $who   = $this->conversation->contact?->displayName() ?? 'a customer';
        $brand = $this->conversation->brand?->name;

        return [
            'title'   => 'New WhatsApp message' . ($brand ? " — {$brand}" : ''),
            'message' => $who . ': ' . Str::limit($this->message->previewLine(), 120),
            // No client_id: a WhatsApp contact is a customer of a brand, not
            // necessarily a client record in the CRM.
            'client_id' => null,
            'url'       => route('whatsapp.inbox', ['conversation' => $this->conversation->id]),
        ];
    }
}
