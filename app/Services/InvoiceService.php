<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Portal\InvoiceCreated;
use App\Services\Portal\NotifiesPortalUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceService
{
    use NotifiesPortalUsers;

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            $invoice = Invoice::create(array_merge($data, [
                'client_id'      => $client->id,
                'invoice_number' => $this->nextInvoiceNumber(),
                'issued_by'      => $actor->id,
                'issued_date'    => $data['issued_date'] ?? now()->toDateString(),
                'status'         => Invoice::STATUS_UNPAID,
            ]));

            $this->activityLog->log('Invoice', 'Created', $client->id, null, ['invoice_number' => $invoice->invoice_number]);
            $this->notifyPortalUsers($client, new InvoiceCreated($invoice));

            return $invoice;
        });
    }

    /**
     * Auto-transitions Unpaid -> Partially Paid -> Paid based on the sum of
     * linked payments vs total_payable, unless the invoice is already in a
     * staff-set terminal state (Refunded/Non-Refundable/Cancelled), which
     * only a direct staff action ever sets.
     */
    public function recalculateStatus(Invoice $invoice): Invoice
    {
        $invoice->refresh();

        if ($invoice->isTerminal()) {
            return $invoice;
        }

        $paid = $invoice->paid_amount;

        $status = match (true) {
            $paid <= 0                          => Invoice::STATUS_UNPAID,
            $paid < (float) $invoice->total_payable => Invoice::STATUS_PARTIALLY_PAID,
            default                              => Invoice::STATUS_PAID,
        };

        if ($status !== $invoice->status) {
            $invoice->update(['status' => $status]);
        }

        return $invoice;
    }

    public function nextInvoiceNumber(): string
    {
        $count = Invoice::withTrashed()->count() + 1;

        return 'INV-' . now()->format('Ym') . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}
