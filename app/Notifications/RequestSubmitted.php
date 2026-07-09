<?php

namespace App\Notifications;

use App\Models\EmployeeRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class RequestSubmitted extends Notification
{
    public function __construct(
        private readonly EmployeeRequest $request,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
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
