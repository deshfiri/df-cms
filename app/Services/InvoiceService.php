<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Portal\InvoiceCreated;
use App\Services\Portal\NotifiesPortalUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

            $this->activityLog->log('Invoice', 'Created', $client->id, null, [
                'invoice_number'      => $invoice->invoice_number,
                'payment_category_id' => $invoice->payment_category_id,
                'total_payable'       => $invoice->total_payable,
            ]);
            $this->notifyPortalUsers($client, new InvoiceCreated($invoice));

            return $invoice;
        });
    }

    /**
     * Staff correction of a charge — its category, wording, total or due date,
     * or a manual status such as Cancelled.
     *
     * A total can't be cut below what has already been received; that would
     * leave money on record with nothing owed for it.
     *
     * @throws ValidationException
     */
    public function update(Invoice $invoice, array $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $old     = $invoice->only(array_keys($data));

            if (array_key_exists('total_payable', $data)) {
                $paid = $invoice->paid_amount;
                if (round((float) $data['total_payable'], 2) < $paid) {
                    throw ValidationException::withMessages([
                        'total_payable' => '৳' . number_format($paid, 2) . ' has already been received against this charge — the total can\'t be less than that.',
                    ]);
                }
            }

            $wasTerminal = $invoice->isTerminal();
            $invoice->update($data);

            // Re-derive the status when the balance moved or a closed charge was
            // reopened; an explicit status set in the same breath wins otherwise.
            $totalChanged = array_key_exists('total_payable', $data) && (float) ($old['total_payable'] ?? 0) !== (float) $invoice->total_payable;
            $reopened     = $wasTerminal && !$invoice->isTerminal();
            if ($totalChanged || $reopened) {
                $this->recalculateStatus($invoice);
            }

            // Payments inherit a charge's category; keep them in step with it.
            if (array_key_exists('payment_category_id', $data)) {
                $invoice->payments()->update(['payment_category_id' => $invoice->payment_category_id]);
            }

            $this->activityLog->log('Invoice', 'Updated', $invoice->client_id, $old + ['invoice_number' => $invoice->invoice_number], $data);

            return $invoice->fresh();
        });
    }

    /** The shape the Payments tab and the record-payment pickers read. */
    public function present(Invoice $invoice): array
    {
        return [
            'id'             => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'title'          => $invoice->title,
            'description'    => $invoice->description,
            'category'       => $invoice->category ? ['id' => $invoice->category->id, 'name' => $invoice->category->name] : null,
            'total_payable'  => (float) $invoice->total_payable,
            'paid_amount'    => $invoice->paid_amount,
            'due_amount'     => $invoice->due_amount,
            'status'         => $invoice->status,
            'is_terminal'    => $invoice->isTerminal(),
            'is_open'        => $invoice->isOpen(),
            'due_date'       => $invoice->due_date?->toDateString(),
            'is_overdue'     => $invoice->isOpen() && $invoice->due_date !== null && $invoice->due_date->lt(today()),
            'issued_date'    => $invoice->issued_date?->toDateString(),
            'remarks'        => $invoice->remarks,
        ];
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
