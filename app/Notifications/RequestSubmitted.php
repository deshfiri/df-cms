<?php

namespace App\Notifications;

use App\Models\EmployeeRequest;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequestSubmitted extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly EmployeeRequest $request,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $requester = $this->request->requestedBy?->name ?? 'Someone';

        return [
            'title'      => 'New request awaiting review',
            'message'    => "{$requester} submitted a request: \"{$this->request->subject}\".",
            'request_id' => $this->request->id,
            'url'        => route('requests.index'),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $requester = $this->request->requestedBy?->name ?? 'Someone';

        return (new MailMessage)
            ->subject("New request: {$this->request->subject}")
            ->line("{$requester} submitted a request: \"{$this->request->subject}\".")
            ->line($this->request->message)
            ->action('Review Request', route('requests.index'));
    }
}
