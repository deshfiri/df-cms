<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording money.
 *
 * A payment either stands alone (the long-standing Paid / Partial / Unpaid
 * record) or is taken against a charge — "Social Media Ads, ৳20,000" — in which
 * case it is money received, always stored as Paid, and the charge works out
 * for itself whether it is now Partially Paid or settled.
 *
 * Every write that can move a charge's balance re-derives that charge's status
 * afterwards, so a deleted or corrected payment never leaves a charge claiming
 * to be paid.
 */
class PaymentService
{
    /** Fields that describe a charge to open inline; never columns on payments. */
    private const CHARGE_FIELDS = ['charge_total', 'charge_title', 'charge_due_date'];

    public function __construct(
        private readonly ActivityLogService    $activityLog,
        private readonly ChangeApprovalService $changeApproval,
        private readonly InvoiceService        $invoices,
    ) {}

    /**
     * @param  array  $data  Payment fields, plus optionally `invoice_id` to pay down an
     *                       existing charge, or `charge_total` (+ title, due date) to
     *                       open a new charge and take this payment against it.
     *
     * @throws ValidationException when the charge can't take this payment
     */
    public function create(Client $client, array $data): Payment
    {
        return DB::transaction(function () use ($client, $data) {
            $charge = $this->chargeFor($client, $data);
            $data   = collect($data)->except(self::CHARGE_FIELDS)->all();

            if ($charge) {
                $data = $this->againstCharge($charge, $data);
            }

            $data['client_id']  = $client->id;
            $data['created_by'] = Auth::id();

            $payment = Payment::create($data);
            $this->activityLog->log('Payment', 'Created', $client->id, null, $data);

            if ($charge) {
                $this->invoices->recalculateStatus($charge);
            }

            return $payment;
        });
    }

    public function update(Payment $payment, array $data): Payment
    {
        $data = collect($data)->except(self::CHARGE_FIELDS)->all();

        $this->changeApproval->guard(Payment::class, $payment->id, $payment->only(array_keys($data)), $data, Auth::user());

        return DB::transaction(function () use ($payment, $data) {
            $old          = $payment->toArray();
            $oldInvoiceId = $payment->invoice_id;
            $newInvoiceId = array_key_exists('invoice_id', $data) ? $data['invoice_id'] : $oldInvoiceId;

            $charge = null;
            if ($newInvoiceId) {
                $charge = $this->lockCharge((int) $newInvoiceId, $payment->client_id);

                // Moving a payment onto a closed charge is refused; leaving one
                // where it already was is not — it was accepted when recorded.
                if ((int) $newInvoiceId !== (int) $oldInvoiceId && $charge->isTerminal()) {
                    $this->refuse('invoice_id', "{$charge->invoice_number} is {$charge->status} and can't take payments.");
                }

                $data['amount'] = $data['amount'] ?? $payment->amount;
                $data = $this->againstCharge($charge, $data, exceptPaymentId: $payment->id, checkOpen: false);
            }

            $payment->update($data);
            $this->activityLog->log('Payment', 'Updated', $payment->client_id, $old, $data);

            // Both sides of a move: the charge it left and the one it joined.
            if ($oldInvoiceId && (int) $oldInvoiceId !== (int) $newInvoiceId && ($left = Invoice::find($oldInvoiceId))) {
                $this->invoices->recalculateStatus($left);
            }
            if ($charge) {
                $this->invoices->recalculateStatus($charge);
            }

            return $payment->fresh();
        });
    }

    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $charge = $payment->invoice_id ? Invoice::find($payment->invoice_id) : null;

            $this->activityLog->log('Payment', 'Deleted', $payment->client_id, $payment->toArray());
            $payment->delete();

            if ($charge) {
                $this->invoices->recalculateStatus($charge);
            }
        });
    }

    /**
     * Totals for the client's Payments tab.
     *
     * The first four keys are what the page has always read. The rest break the
     * money down the way a client is actually billed — per category.
     */
    public function summaryForClient(Client $client): array
    {
        $payments = $client->payments()->get(['id', 'status', 'amount', 'payment_category_id', 'invoice_id', 'created_at']);
        $charges  = $client->invoices()->withPaidTotal()->get();

        $billable = $charges->where('status', '!=', Invoice::STATUS_CANCELLED);
        $open     = $charges->filter(fn (Invoice $i) => !$i->isTerminal());
        $received = $payments->where('status', 'Paid');

        return [
            'total_paid'    => $received->sum('amount'),
            'total_partial' => $payments->where('status', 'Partial')->sum('amount'),
            'count'         => $payments->count(),
            'latest_status' => $payments->first()?->status,

            'total_billed'      => round($billable->sum(fn (Invoice $i) => (float) $i->total_payable), 2),
            'total_outstanding' => round($open->sum(fn (Invoice $i) => $i->due_amount), 2),
            'open_charges'      => $open->filter(fn (Invoice $i) => $i->due_amount > 0)->count(),
            'by_category'       => $this->breakdown($billable, $open, $received),
        ];
    }

    /**
     * Billed, received and still due per category, in the categories' own order,
     * with anything recorded before categories existed grouped as Uncategorised.
     */
    private function breakdown($billable, $open, $received): array
    {
        $ids = $billable->pluck('payment_category_id')
            ->merge($received->pluck('payment_category_id'))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $categories = PaymentCategory::whereIn('id', $ids->filter())->ordered()->get(['id', 'name']);

        $rows = $categories->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all();
        if ($ids->contains(null)) {
            $rows[] = ['id' => null, 'name' => 'Uncategorised'];
        }

        return array_values(array_map(function (array $row) use ($billable, $open, $received) {
            $inCategory = fn ($item) => ($item->payment_category_id === null ? null : (int) $item->payment_category_id) === $row['id'];

            $billed = round($billable->filter($inCategory)->sum(fn (Invoice $i) => (float) $i->total_payable), 2);
            $paid   = round((float) $received->filter($inCategory)->sum('amount'), 2);
            $due    = round($open->filter($inCategory)->sum(fn (Invoice $i) => $i->due_amount), 2);

            return $row + [
                'billed'       => $billed,
                'received'     => $paid,
                'due'          => $due,
                'open_charges' => $open->filter($inCategory)->filter(fn (Invoice $i) => $i->due_amount > 0)->count(),
                'percent'      => $billed > 0 ? (int) min(100, round($paid / $billed * 100)) : null,
            ];
        }, $rows));
    }

    /**
     * The charge this payment goes against: an existing one, a new one opened on
     * the spot, or none at all for a standalone payment.
     */
    private function chargeFor(Client $client, array $data): ?Invoice
    {
        if (!empty($data['invoice_id'])) {
            return $this->lockCharge((int) $data['invoice_id'], $client->id);
        }

        if (!empty($data['charge_total'])) {
            $charge = $this->invoices->create($client, [
                'payment_category_id' => $data['payment_category_id'] ?? null,
                'title'               => $data['charge_title'] ?? null,
                'total_payable'       => $data['charge_total'],
                'due_date'            => $data['charge_due_date'] ?? null,
            ], Auth::user());

            return $this->lockCharge($charge->id, $client->id);
        }

        return null;
    }

    /** Re-read under a row lock, so two payments recorded at once can't both fit the same balance. */
    private function lockCharge(int $invoiceId, int $clientId): Invoice
    {
        $charge = Invoice::whereKey($invoiceId)->lockForUpdate()->first();

        if (!$charge || (int) $charge->client_id !== $clientId) {
            $this->refuse('invoice_id', 'That charge does not belong to this client.');
        }

        return $charge;
    }

    /**
     * Shape a payment taken against a charge, refusing one the charge can't hold.
     *
     * It is money received, so it is Paid whatever status was sent, and it
     * takes the charge's category so the two can never disagree.
     */
    private function againstCharge(Invoice $charge, array $data, ?int $exceptPaymentId = null, bool $checkOpen = true): array
    {
        if ($checkOpen && $charge->isTerminal()) {
            $this->refuse('invoice_id', "{$charge->invoice_number} is {$charge->status} and can't take payments.");
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            $this->refuse('amount', 'Enter the amount received against this charge.');
        }

        $alreadyPaid = (float) $charge->payments()
            ->where('status', 'Paid')
            ->when($exceptPaymentId, fn ($q) => $q->whereKeyNot($exceptPaymentId))
            ->sum('amount');
        $due = max(0, round((float) $charge->total_payable - $alreadyPaid, 2));

        if ($amount > $due) {
            $this->refuse('amount', $due > 0
                ? 'Only ৳' . number_format($due, 2) . " is still due on {$charge->invoice_number}. Record at most that, or raise the charge total first."
                : "{$charge->invoice_number} is already fully paid.");
        }

        $data['invoice_id']          = $charge->id;
        $data['payment_category_id'] = $charge->payment_category_id ?? ($data['payment_category_id'] ?? null);
        $data['status']              = 'Paid';
        $data['amount']              = $amount;

        return $data;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
