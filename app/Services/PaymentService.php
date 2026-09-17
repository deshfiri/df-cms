<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\PendingChange;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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

    // ── Corrections: every edit and deletion is requested, reviewed, recorded ──
    //
    // Nobody changes a payment silently. Someone allowed to approve (Super Admin,
    // Manager — ChangeApprovalService::isPrivileged) has their change applied at
    // once; anyone else's waits for one of them. Either way a PendingChange row
    // is written and never overwritten or deleted: original values, requested
    // values, who asked, when, why, who approved or rejected it, when, and what
    // happened. That table is the payment's audit trail (see history()).
    //
    // Nobody reviews their own request, a payment can have only one request
    // waiting at a time, and an approval re-checks that the payment still holds
    // the values the request was made against — a request approved after
    // someone else changed the payment would otherwise overwrite that change.

    /** Fields a correction may touch, in the order a history shows them. */
    public const CORRECTABLE_FIELDS = [
        'amount', 'payment_date', 'status', 'payment_method', 'transaction_number',
        'invoice_id', 'payment_category_id', 'remarks',
    ];

    public const FIELD_LABELS = [
        'amount' => 'Amount', 'payment_date' => 'Payment date', 'status' => 'Status',
        'payment_method' => 'Method', 'transaction_number' => 'Transaction #',
        'invoice_id' => 'Charge', 'payment_category_id' => 'Category', 'remarks' => 'Remarks',
    ];

    /**
     * Ask for a payment to be corrected.
     *
     * @return array{applied:bool, change:PendingChange, payment:Payment}
     *
     * @throws ValidationException  nothing would change, another request is waiting,
     *                              or the change could never be applied
     */
    public function requestUpdate(Payment $payment, array $data, string $reason, User $actor): array
    {
        $requested = collect($data)->only(self::CORRECTABLE_FIELDS)->all();

        return DB::transaction(function () use ($payment, $requested, $reason, $actor) {
            $payment = $this->lockPayment($payment->id);
            $this->refuseIfWaiting($payment);

            $before  = $this->snapshot($payment, array_keys($requested));
            $changes = array_filter(
                $requested,
                fn ($value, $field) => $this->normalize($field, $value) !== $before[$field],
                ARRAY_FILTER_USE_BOTH,
            );

            if (!$changes) {
                $this->refuse('amount', 'Nothing would change — these are the values already recorded.');
            }

            $old = array_intersect_key($before, $changes);
            $new = [];
            foreach ($changes as $field => $value) {
                $new[$field] = $this->normalize($field, $value);
            }

            if ($this->changeApproval->isPrivileged($actor)) {
                $updated = $this->applyUpdate($payment, $new);
                $change  = $this->record($payment, $old, $new, $reason, $actor, PendingChange::STATUS_APPLIED);

                return ['applied' => true, 'change' => $change, 'payment' => $updated];
            }

            // Refuse now what could never be applied, rather than queue it.
            $this->dryRun(fn () => $this->applyUpdate($this->lockPayment($payment->id), $new));

            $change = $this->record($payment, $old, $new, $reason, $actor, PendingChange::STATUS_PENDING);
            $this->activityLog->log('Payment', 'Change Requested', $payment->client_id, $old, $new + ['reason' => $reason]);
            DB::afterCommit(fn () => $this->changeApproval->notifyApprovers($change));

            return ['applied' => false, 'change' => $change, 'payment' => $payment];
        });
    }

    /**
     * Ask for a payment to be deleted. The full record is kept on the request,
     * so the audit trail still shows what was removed.
     *
     * @return array{applied:bool, change:PendingChange}
     */
    public function requestDelete(Payment $payment, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = $this->lockPayment($payment->id);
            $this->refuseIfWaiting($payment);

            $old = $this->snapshot($payment, self::CORRECTABLE_FIELDS);
            $new = [PendingChange::ACTION_KEY => PendingChange::ACTION_DELETE];

            if ($this->changeApproval->isPrivileged($actor)) {
                $change = $this->record($payment, $old, $new, $reason, $actor, PendingChange::STATUS_APPLIED);
                $this->applyDelete($payment);

                return ['applied' => true, 'change' => $change];
            }

            $this->dryRun(fn () => $this->applyDelete($this->lockPayment($payment->id)));

            $change = $this->record($payment, $old, $new, $reason, $actor, PendingChange::STATUS_PENDING);
            $this->activityLog->log('Payment', 'Deletion Requested', $payment->client_id, $old, ['reason' => $reason]);
            DB::afterCommit(fn () => $this->changeApproval->notifyApprovers($change));

            return ['applied' => false, 'change' => $change];
        });
    }

    /** @throws ValidationException|AuthorizationException */
    public function approveChange(PendingChange $change, User $approver, ?string $note = null): PendingChange
    {
        return DB::transaction(function () use ($change, $approver, $note) {
            $change = $this->lockForReview($change, $approver);

            $payment = Payment::whereKey($change->model_id)->lockForUpdate()->first();
            if (!$payment) {
                $this->refuse('change', 'This payment no longer exists. Reject the request instead.');
            }

            $now = $this->snapshot($payment, array_keys(array_diff_key($change->old_values, [PendingChange::ACTION_KEY => true])));
            foreach ($change->old_values as $field => $value) {
                if (array_key_exists($field, $now) && $now[$field] !== $this->normalize($field, $value)) {
                    $this->refuse('change', 'The payment has changed since this was requested (' . (self::FIELD_LABELS[$field] ?? $field)
                        . '). Reject it and ask for a fresh request against the current values.');
                }
            }

            $change->isDeletion()
                ? $this->applyDelete($payment)
                : $this->applyUpdate($payment, $change->new_values);

            $change->update([
                'status'      => PendingChange::STATUS_APPROVED,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'applied_at'  => now(),
                'review_note' => $note,
            ]);

            return $change->fresh();
        });
    }

    /** @throws ValidationException|AuthorizationException */
    public function rejectChange(PendingChange $change, User $approver, ?string $note = null): PendingChange
    {
        return DB::transaction(function () use ($change, $approver, $note) {
            $change = $this->lockForReview($change, $approver);

            $change->update([
                'status'      => PendingChange::STATUS_REJECTED,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $this->activityLog->log('Payment', 'Change Rejected', Payment::find($change->model_id)?->client_id, $change->old_values, $change->new_values + ['note' => $note]);

            return $change->fresh();
        });
    }

    /**
     * Every correction ever requested for a payment, newest first — including
     * those for a payment since deleted.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(int $paymentId): array
    {
        $labels = [
            PendingChange::STATUS_PENDING  => 'Waiting for approval',
            PendingChange::STATUS_APPROVED => 'Approved and applied',
            PendingChange::STATUS_REJECTED => 'Rejected',
            PendingChange::STATUS_APPLIED  => 'Applied by an approver',
        ];

        return PendingChange::for(Payment::class, $paymentId)
            ->with(['requestedBy:id,name', 'reviewedBy:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PendingChange $c) => [
                'id'           => $c->id,
                'action'       => $c->isDeletion() ? 'delete' : 'edit',
                'status'       => $c->status,
                'status_label' => $labels[$c->status] ?? $c->status,
                'reason'       => $c->reason,
                'changes'      => $c->isDeletion() ? [] : collect($c->new_values)->map(fn ($to, $field) => [
                    'field' => $field,
                    'label' => self::FIELD_LABELS[$field] ?? $field,
                    'from'  => $c->old_values[$field] ?? null,
                    'to'    => $to,
                ])->values()->all(),
                'snapshot'     => $c->isDeletion() ? $c->old_values : null,
                'requested_by' => $c->requestedBy?->name,
                'requested_at' => $c->created_at?->toIso8601String(),
                'reviewed_by'  => $c->reviewedBy?->name,
                'reviewed_at'  => $c->reviewed_at?->toIso8601String(),
                'applied_at'   => $c->applied_at?->toIso8601String(),
                'review_note'  => $c->review_note,
            ])
            ->all();
    }

    public function hasWaitingChange(int $paymentId): bool
    {
        return PendingChange::for(Payment::class, $paymentId)->pending()->exists();
    }

    private function lockPayment(int $id): Payment
    {
        return Payment::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function refuseIfWaiting(Payment $payment): void
    {
        if (PendingChange::for(Payment::class, $payment->id)->pending()->lockForUpdate()->exists()) {
            $this->refuse('payment', 'A change to this payment is already waiting for approval. It has to be approved or rejected before another can be requested.');
        }
    }

    /** Re-read under a lock and check the reviewer may rule on it. */
    private function lockForReview(PendingChange $change, User $approver): PendingChange
    {
        $change = PendingChange::whereKey($change->id)->lockForUpdate()->firstOrFail();

        if ($change->model_type !== Payment::class) {
            throw new \InvalidArgumentException('Not a payment change.');
        }
        if ($change->status !== PendingChange::STATUS_PENDING) {
            $this->refuse('change', 'This change has already been reviewed.');
        }
        if (!$this->changeApproval->isPrivileged($approver)) {
            throw new AuthorizationException('Only a Super Admin or Manager can review payment changes.');
        }
        if ((int) $change->requested_by === (int) $approver->id) {
            throw new AuthorizationException('You cannot review your own request. Another approver has to.');
        }

        return $change;
    }

    private function record(Payment $payment, array $old, array $new, string $reason, User $actor, string $status): PendingChange
    {
        $applied = $status === PendingChange::STATUS_APPLIED;

        return PendingChange::create([
            'model_type'   => Payment::class,
            'model_id'     => $payment->id,
            // Whose payment it was, so the trail can still be found under the
            // client once the payment itself is gone.
            'old_values'   => ['client_id' => (int) $payment->client_id] + $old,
            'new_values'   => $new,
            'reason'       => $reason,
            'requested_by' => $actor->id,
            'status'       => $status,
            'reviewed_by'  => $applied ? $actor->id : null,
            'reviewed_at'  => $applied ? now() : null,
            'applied_at'   => $applied ? now() : null,
        ]);
    }

    /** Run something for its checks only; whatever it wrote is undone. */
    private function dryRun(callable $check): void
    {
        DB::beginTransaction();
        try {
            $check();
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(Payment $payment, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->normalize($field, $payment->getAttribute($field));
        }

        return $values;
    }

    /** One comparable form per field, so "10000" and 10000.00 are the same amount. */
    private function normalize(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($field) {
            // A fixed two-decimal string: it survives JSON exactly, where a
            // float like 10000.0 comes back as the integer 10000.
            'amount'                            => number_format(round((float) $value, 2), 2, '.', ''),
            'invoice_id', 'payment_category_id', 'client_id' => (int) $value,
            'payment_date'                      => \Illuminate\Support\Carbon::parse($value)->toDateString(),
            default                             => (string) $value,
        };
    }

    /** Apply a correction. Only ever reached through requestUpdate() or approveChange(). */
    private function applyUpdate(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $old          = $payment->toArray();
            $oldInvoiceId = $payment->invoice_id;
            $newInvoiceId = array_key_exists('invoice_id', $data) ? $data['invoice_id'] : $oldInvoiceId;

            $this->refuseIfRefundsForbid($payment, $data, $newInvoiceId);

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

    /**
     * Money refunded or being refunded from a payment pins it: the amount can't
     * drop below what is committed, it can't stop being money received, and it
     * can't move to another charge (its refunds are recorded against this one).
     */
    private function refuseIfRefundsForbid(Payment $payment, array $data, mixed $newInvoiceId): void
    {
        $committed = round((float) $payment->refunds()->whereIn('status', Refund::COMMITTED_STATUSES)->sum('amount'), 2);
        if ($committed <= 0) {
            return;
        }

        if (array_key_exists('amount', $data) && round((float) $data['amount'], 2) < $committed) {
            $this->refuse('amount', '৳' . number_format($committed, 2) . ' of this payment has been refunded or is being refunded, so the amount can\'t go below that.');
        }
        if ((int) $newInvoiceId !== (int) $payment->invoice_id) {
            $this->refuse('invoice_id', 'This payment has refunds against its charge, so it can\'t be moved to another one.');
        }
        if (array_key_exists('status', $data) && !in_array($data['status'], ['Paid', 'Partial'], true)) {
            $this->refuse('status', 'Money has been refunded from this payment, so it can\'t be marked ' . $data['status'] . '.');
        }
    }

    /** Remove a payment. Only ever reached through requestDelete() or approveChange(). */
    private function applyDelete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            // A refund, even a rejected one, is part of this payment's record.
            if ($payment->refunds()->exists()) {
                $this->refuse('payment', 'This payment has refunds on record, so it can\'t be deleted — its history has to stay intact. Correct it instead.');
            }

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

        // Paid back to the client. Only completed refunds have left the account.
        $refunds = Refund::where('client_id', $client->id)
            ->whereIn('status', Refund::COMMITTED_STATUSES)
            ->with('payment:id,payment_category_id')
            ->get(['id', 'payment_id', 'amount', 'status']);
        $refunded = $refunds->where('status', Refund::STATUS_COMPLETED);

        return [
            'total_paid'    => $received->sum('amount'),
            'total_partial' => $payments->where('status', 'Partial')->sum('amount'),
            'count'         => $payments->count(),
            'latest_status' => $payments->first()?->status,

            'total_refunded'    => round((float) $refunded->sum('amount'), 2),
            'refunds_in_flight' => round((float) $refunds->where('status', '!=', Refund::STATUS_COMPLETED)->sum('amount'), 2),
            'net_received'      => round((float) $received->sum('amount') - (float) $refunded->sum('amount'), 2),

            'total_billed'      => round($billable->sum(fn (Invoice $i) => (float) $i->total_payable), 2),
            'total_outstanding' => round($open->sum(fn (Invoice $i) => $i->due_amount), 2),
            'open_charges'      => $open->filter(fn (Invoice $i) => $i->due_amount > 0)->count(),
            'by_category'       => $this->breakdown($billable, $open, $received, $refunded),
        ];
    }

    /**
     * Billed, received and still due per category, in the categories' own order,
     * with anything recorded before categories existed grouped as Uncategorised.
     */
    private function breakdown($billable, $open, $received, $refunded = null): array
    {
        $refunded ??= collect();
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

        return array_values(array_map(function (array $row) use ($billable, $open, $received, $refunded) {
            $inCategory = fn ($item) => ($item->payment_category_id === null ? null : (int) $item->payment_category_id) === $row['id'];

            $billed   = round($billable->filter($inCategory)->sum(fn (Invoice $i) => (float) $i->total_payable), 2);
            $backOut  = round((float) $refunded->filter(fn ($r) => $r->payment && $inCategory($r->payment))->sum('amount'), 2);
            // Received and kept.
            $paid     = round((float) $received->filter($inCategory)->sum('amount') - $backOut, 2);
            $due      = round($open->filter($inCategory)->sum(fn (Invoice $i) => $i->due_amount), 2);

            return $row + [
                'billed'       => $billed,
                'received'     => $paid,
                'refunded'     => $backOut,
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

        // What the other payments on this charge still hold, net of what was
        // paid back from them; and what this payment itself has paid back, so a
        // correction is measured by what it will keep.
        $alreadyPaid = (float) $charge->payments()
            ->where('status', 'Paid')
            ->when($exceptPaymentId, fn ($q) => $q->whereKeyNot($exceptPaymentId))
            ->sum('amount');
        $refundedOthers = (float) Refund::where('invoice_id', $charge->id)
            ->where('status', Refund::STATUS_COMPLETED)
            ->when($exceptPaymentId, fn ($q) => $q->where('payment_id', '!=', $exceptPaymentId))
            ->sum('amount');
        $ownRefunded = $exceptPaymentId
            ? (float) Refund::where('payment_id', $exceptPaymentId)->where('status', Refund::STATUS_COMPLETED)->sum('amount')
            : 0.0;

        $due = max(0, round((float) $charge->total_payable - ($alreadyPaid - $refundedOthers), 2));

        if (round($amount - $ownRefunded, 2) > $due) {
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
