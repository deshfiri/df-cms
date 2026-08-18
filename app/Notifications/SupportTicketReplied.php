<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

class SupportTicketReplied extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly SupportTicketReply $reply,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => "Client replied: {$this->ticket->ticket_number}",
            'message' => "{$this->ticket->client->client_name} replied to \"{$this->ticket->subject}\".",
            'url'     => route('support-tickets.show', $this->ticket),
        ];
    }
}
