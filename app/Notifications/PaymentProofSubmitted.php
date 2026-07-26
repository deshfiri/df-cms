<?php

namespace App\Notifications;

use App\Models\PaymentProofSubmission;
use Illuminate\Notifications\Notification;

class PaymentProofSubmitted extends Notification
{
    public function __construct(
        private readonly PaymentProofSubmission $proof,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $client = $this->proof->client;

        return [
            'title'   => 'Payment proof submitted',
            'message' => "{$client->client_name} submitted proof of payment for ৳" . number_format((float) $this->proof->amount_claimed, 2),
            'url'     => route('clients.show', $client),
        ];
    }
}
