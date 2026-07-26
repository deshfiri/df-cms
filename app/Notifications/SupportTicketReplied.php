<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Notifications\Notification;

class SupportTicketReplied extends Notification
{
    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly SupportTicketReply $reply,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
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
