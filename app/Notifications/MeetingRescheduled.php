<?php

namespace App\Notifications;

use App\Models\ClientMeeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingRescheduled extends Notification
{
    public function __construct(
        private readonly ClientMeeting $meeting,
        private readonly Carbon $previousTime,
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return $notifiable instanceof User ? ['database'] : ['mail'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title'     => 'Meeting Rescheduled',
            'message'   => "{$this->meeting->title} moved from {$this->previousTime->format('d M Y, h:i A')} to {$this->meeting->scheduled_at->format('d M Y, h:i A')}",
            'client_id' => $this->meeting->client_id,
            'url'       => route('clients.show', $this->meeting->client_id),
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject("Meeting Rescheduled: {$this->meeting->title}")
            ->line("This meeting has moved from {$this->previousTime->format('d M Y, h:i A')} to {$this->meeting->scheduled_at->format('d M Y, h:i A')}.");

        if ($this->meeting->join_url) {
            $mail->action('Join Meeting', $this->meeting->join_url);
        }

        return $mail;
    }
}
