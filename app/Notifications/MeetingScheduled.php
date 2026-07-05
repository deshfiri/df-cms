<?php

namespace App\Notifications;

use App\Models\ClientMeeting;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingScheduled extends Notification
{
    public function __construct(private readonly ClientMeeting $meeting)
    {
    }

    public function via(mixed $notifiable): array
    {
        return $notifiable instanceof User ? ['database'] : ['mail'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title'     => 'Meeting Scheduled',
            'message'   => "{$this->meeting->title} with {$this->meeting->client?->client_name} on {$this->meeting->scheduled_at->format('d M Y, h:i A')}",
            'client_id' => $this->meeting->client_id,
            'url'       => route('clients.show', $this->meeting->client_id),
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject("Meeting Scheduled: {$this->meeting->title}")
            ->line("A meeting has been scheduled for {$this->meeting->scheduled_at->format('d M Y, h:i A')}.")
            ->line("Duration: {$this->meeting->duration_human}");

        if ($this->meeting->join_url) {
            $mail->action('Join Meeting', $this->meeting->join_url);
        }

        return $mail;
    }
}
