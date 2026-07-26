<?php

namespace App\Notifications\Portal;

use App\Models\Invoice;
use Illuminate\Notifications\Notification;

class PaymentDueSoon extends Notification
{
    public function __construct(
        private readonly Invoice $invoice,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'   => "Payment due soon: {$this->invoice->invoice_number}",
            'message' => "৳" . number_format($this->invoice->due_amount, 2) . " is due on {$this->invoice->due_date->format('d M Y')}.",
            'url'     => route('portal.invoices.show', $this->invoice),
        ];
    }
}
