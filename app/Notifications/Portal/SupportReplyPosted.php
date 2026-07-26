<?php

namespace App\Notifications\Portal;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Notifications\Notification;

class SupportReplyPosted extends Notification
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
            'title'   => "Reply on {$this->ticket->ticket_number}",
            'message' => "Support replied to \"{$this->ticket->subject}\".",
            'url'     => route('portal.support.show', $this->ticket),
        ];
    }
}
