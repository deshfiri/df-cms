<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Notification;

class SupportTicketCreated extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly SupportTicket $ticket,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => "New support ticket: {$this->ticket->ticket_number}",
            'message' => "{$this->ticket->client->client_name}: {$this->ticket->subject}",
            'url'     => route('support-tickets.show', $this->ticket),
        ];
    }
}
