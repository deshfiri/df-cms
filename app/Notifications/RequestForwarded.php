<?php

namespace App\Notifications;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequestForwarded extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly EmployeeRequest $request,
        private readonly User $forwardedBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'      => 'A request was forwarded to you',
            'message'    => "{$this->forwardedBy->name} forwarded a request to you: \"{$this->request->subject}\".",
            'request_id' => $this->request->id,
            'url'        => route('requests.index'),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Request forwarded to you: {$this->request->subject}")
            ->line("{$this->forwardedBy->name} forwarded a request to you: \"{$this->request->subject}\".")
            ->line($this->request->message)
            ->action('Review Request', route('requests.index'));
    }
}
