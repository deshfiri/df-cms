<?php

namespace App\Notifications\Portal;

use App\Models\PaymentProofSubmission;
use Illuminate\Notifications\Notification;

class PaymentVerified extends Notification
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
        return [
            'title'   => 'Payment verified',
            'message' => 'Your payment of ৳' . number_format((float) $this->proof->amount_claimed, 2) . ' has been verified.',
            'url'     => route('portal.invoices.index'),
        ];
    }
}
