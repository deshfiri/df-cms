<?php

namespace App\Notifications\Portal;

use App\Models\Invoice;
use Illuminate\Notifications\Notification;

class InvoiceCreated extends Notification
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
            'title'   => "New invoice {$this->invoice->invoice_number}",
            'message' => "An invoice for ৳" . number_format((float) $this->invoice->total_payable, 2) . ' has been issued.',
            'url'     => route('portal.invoices.show', $this->invoice),
        ];
    }
}
