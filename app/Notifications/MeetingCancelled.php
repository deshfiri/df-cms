<?php

namespace App\Notifications;

use App\Models\ClientMeeting;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingCancelled extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(private readonly ClientMeeting $meeting)
    {
    }

    public function via(mixed $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'broadcast'] : ['mail'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title'     => 'Meeting Cancelled',
            'message'   => "{$this->meeting->title} scheduled for {$this->meeting->scheduled_at->format('d M Y, h:i A')} was cancelled",
            'client_id' => $this->meeting->client_id,
            'url'       => route('clients.show', $this->meeting->client_id),
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Meeting Cancelled: {$this->meeting->title}")
            ->line("The meeting scheduled for {$this->meeting->scheduled_at->format('d M Y, h:i A')} has been cancelled.");
    }
}
