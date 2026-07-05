<?php

namespace App\Notifications;

use App\Models\ClientMeeting;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReminder extends Notification
{
    /**
     * @param string $tierLabel Human label for the reminder window, e.g. "24 hours", "1 hour", "15 minutes"
     */
    public function __construct(
        private readonly ClientMeeting $meeting,
        private readonly string $tierLabel,
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return $notifiable instanceof User ? ['database'] : ['mail'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title'     => "Meeting in {$this->tierLabel}",
            'message'   => "{$this->meeting->title} with {$this->meeting->client?->client_name} at {$this->meeting->scheduled_at->format('h:i A')}",
            'client_id' => $this->meeting->client_id,
            'url'       => route('clients.show', $this->meeting->client_id),
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject("Reminder: Meeting in {$this->tierLabel} — {$this->meeting->title}")
            ->line("Your meeting is coming up in {$this->tierLabel}, at {$this->meeting->scheduled_at->format('d M Y, h:i A')}.");

        if ($this->meeting->join_url) {
            $mail->action('Join Meeting', $this->meeting->join_url);
        }

        return $mail;
    }
}
