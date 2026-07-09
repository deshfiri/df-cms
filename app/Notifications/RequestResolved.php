<?php

namespace App\Notifications;

use App\Models\EmployeeRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class RequestResolved extends Notification
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
        $verb = $this->request->status === EmployeeRequest::STATUS_APPROVED ? 'approved' : 'rejected';
        $message = "\"{$this->request->subject}\" was {$verb}";
        if ($this->request->response_note) {
            $message .= ": {$this->request->response_note}";
        } else {
            $message .= '.';
        }

        return [
            'title'      => "Your request was {$verb}",
            'message'    => $message,
            'request_id' => $this->request->id,
            'url'        => route('requests.index'),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $verb = $this->request->status === EmployeeRequest::STATUS_APPROVED ? 'approved' : 'rejected';

        $mail = (new MailMessage)
            ->subject("Your request was {$verb}: {$this->request->subject}")
            ->line("Your request \"{$this->request->subject}\" was {$verb}.");

        if ($this->request->response_note) {
            $mail->line("Note: {$this->request->response_note}");
        }

        return $mail->action('View Request', route('requests.index'));
    }
}
