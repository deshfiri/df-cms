<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingChange;
use App\Models\Refund;
use App\Models\RefundEvent;
use App\Models\User;
use App\Notifications\RefundNeedsAttention;
use App\Policies\RefundPolicy;
use App\Services\Concerns\NotifiesStaff;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Giving money back.
 *
 * ─── The rules ──────────────────────────────────────────────────────────────
 *  - Only money received (a Paid or Partial payment) can be refunded, and never
 *    more than was received: every refund that is requested, under review,
 *    approved, processing or completed counts against the payment, so two
 *    requests can never between them return more than was paid.
 *  - One refund per payment is in progress at a time.
 *  - A refund is decided by someone other than whoever asked for it — a Super
 *    Admin included. Approval re-checks the amount against the payment as it
 *    stands then.
 *  - It moves only along Refund::TRANSITIONS, one step at a time, each step
 *    under a row lock and written to refund_events with who, when and why.
 *  - Paying out ends with a reference (bank / bKash transaction), and only then
 *    is the money counted as gone: the charge's balance and the client's
 *    received total are net of completed refunds. A charge whose money has all
 *    been paid back is marked Refunded.
 *  - Nothing is deleted. A refund that should not happen is rejected or cancelled.
 */
class RefundService
{
    use NotifiesStaff;

    private const STAFF_ROLES = ['Super Admin', 'Manager', 'Accounts'];

    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly InvoiceService     $invoices,
        private readonly RefundPolicy       $policy,
    ) {}

    /** What can still be refunded from a payment. */
    public function refundable(Payment $payment): float
    {
        $committed = (float) $payment->refunds()->whereIn('status', Refund::COMMITTED_STATUSES)->sum('amount');

        return max(0.0, round((float) $payment->amount - $committed, 2));
    }

    /**
     * @param  array{amount:mixed, reason:string, method?:?string}  $data
     *
     * @throws ValidationException|AuthorizationException
     */
    public function request(Payment $payment, array $data, User $actor): Refund
    {
        $this->allow($this->policy->request($actor), 'You are not allowed to request refunds.');

        $amount = round((float) $data['amount'], 2);

        $refund = DB::transaction(function () use ($payment, $data, $actor, $amount) {
            // Locked, so two requests at once are measured one after the other.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (!in_array($payment->status, ['Paid', 'Partial'], true) || (float) $payment->amount <= 0) {
                $this->refuse('payment', "Only money received can be refunded — this payment is {$payment->status}.");
            }
            if ($amount <= 0) {
                $this->refuse('amount', 'Enter the amount to refund.');
            }
            if (PendingChange::for(Payment::class, $payment->id)->pending()->exists()) {
                $this->refuse('payment', 'A correction to this payment is waiting for approval. Ask for the refund once it has been reviewed.');
            }

            $open = $payment->refunds()->whereIn('status', Refund::OPEN_STATUSES)->lockForUpdate()->first();
            if ($open) {
                $this->refuse('payment', "Refund {$open->refund_number} for this payment is still " . strtolower($open->status_label) . '. Finish or cancel it before asking for another.');
            }

            $refundable = $this->refundable($payment);
            if ($amount > $refundable) {
                $this->refuse('amount', $refundable > 0
                    ? 'At most ৳' . number_format($refundable, 2) . ' can still be refunded from this payment.'
                    : 'This payment has already been refunded in full.');
            }

            $refund = Refund::create([
                // Unique placeholder until the id exists; fits refund_number's 30 characters.
                'refund_number' => 'RF-TMP-' . Str::random(16),
                'payment_id'    => $payment->id,
                'client_id'     => $payment->client_id,
                'invoice_id'    => $payment->invoice_id,
                'amount'        => $amount,
                'reason'        => $data['reason'],
                'method'        => $data['method'] ?? null,
                'status'        => Refund::STATUS_REQUESTED,
                'requested_by'  => $actor->id,
            ]);
            $refund->update(['refund_number' => 'RF-' . now()->format('Ym') . '-' . str_pad((string) $refund->id, 5, '0', STR_PAD_LEFT)]);

            $this->record($refund, null, Refund::STATUS_REQUESTED, $actor, $data['reason'], ['amount' => number_format($amount, 2, '.', '')]);
            $this->activityLog->log('Refund', 'Requested', $payment->client_id, null, [
                'refund' => $refund->refund_number, 'payment_id' => $payment->id, 'amount' => $amount, 'reason' => $data['reason'],
            ]);

            return $refund;
        });

        DB::afterCommit(fn () => $this->notifyStaff(self::STAFF_ROLES, new RefundNeedsAttention($refund, 'requested'), 'approve refunds', $actor));

        return $refund;
    }

    public function startReview(Refund $refund, User $actor, ?string $note = null): Refund
    {
        return $this->move($refund, Refund::STATUS_UNDER_REVIEW, $actor, $note,
            guard: fn (Refund $r) => $this->allow($this->policy->review($actor, $r), $this->decisionRefusal($actor, $r)),
            attributes: fn () => ['reviewed_by' => $actor->id, 'review_started_at' => now()],
        );
    }

    public function approve(Refund $refund, User $actor, ?string $note = null): Refund
    {
        $refund = $this->move($refund, Refund::STATUS_APPROVED, $actor, $note,
            guard: function (Refund $r) use ($actor) {
                $this->allow($this->policy->approve($actor, $r), $this->decisionRefusal($actor, $r));

                // The payment as it stands now, not as it stood when asked.
                $payment = Payment::whereKey($r->payment_id)->lockForUpdate()->firstOrFail();
                $others  = (float) $payment->refunds()
                    ->whereIn('status', Refund::COMMITTED_STATUSES)
                    ->whereKeyNot($r->id)
                    ->sum('amount');
                if (round($others + (float) $r->amount, 2) > round((float) $payment->amount, 2)) {
                    $this->refuse('amount', 'The payment no longer covers this refund (৳' . number_format(max(0, (float) $payment->amount - $others), 2) . ' refundable). Reject it and ask again.');
                }
            },
            attributes: fn () => ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note],
        );

        DB::afterCommit(function () use ($refund, $actor) {
            $this->notifyStaff(self::STAFF_ROLES, new RefundNeedsAttention($refund, 'approved'), 'process refunds', $actor);
            $this->notifyRequester($refund, $actor, 'approved');
        });

        return $refund;
    }

    public function reject(Refund $refund, User $actor, string $note): Refund
    {
        $refund = $this->move($refund, Refund::STATUS_REJECTED, $actor, $note,
            guard: fn (Refund $r) => $this->allow($this->policy->reject($actor, $r), $this->decisionRefusal($actor, $r)),
            attributes: fn () => ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note],
        );

        DB::afterCommit(fn () => $this->notifyRequester($refund, $actor, 'rejected'));

        return $refund;
    }

    public function markProcessing(Refund $refund, User $actor, ?string $method = null, ?string $note = null): Refund
    {
        return $this->move($refund, Refund::STATUS_PROCESSING, $actor, $note,
            guard: fn (Refund $r) => $this->allow($this->policy->process($actor, $r), 'You are not allowed to pay out refunds.'),
            attributes: fn (Refund $r) => ['processed_by' => $actor->id, 'processing_at' => now(), 'method' => $method ?: $r->method],
        );
    }

    /** Paid back. The reference is what proves it. */
    public function complete(Refund $refund, User $actor, string $reference, ?string $method = null, ?string $note = null): Refund
    {
        $refund = $this->move($refund, Refund::STATUS_COMPLETED, $actor, $note,
            guard: fn (Refund $r) => $this->allow($this->policy->complete($actor, $r), 'You are not allowed to pay out refunds.'),
            attributes: fn (Refund $r) => [
                'reference'    => $reference,
                'method'       => $method ?: $r->method,
                'completed_at' => now(),
                'processed_by' => $r->processed_by ?? $actor->id,
            ],
            meta: ['reference' => $reference],
            after: fn (Refund $r) => $this->settleCharge($r, $actor),
        );

        DB::afterCommit(fn () => $this->notifyRequester($refund, $actor, 'completed'));

        return $refund;
    }

    public function cancel(Refund $refund, User $actor, string $reason): Refund
    {
        return $this->move($refund, Refund::STATUS_CANCELLED, $actor, $reason,
            guard: fn (Refund $r) => $this->allow($this->policy->cancel($actor, $r),
                $r->status === Refund::STATUS_PROCESSING
                    ? 'This refund is already being paid out and can no longer be cancelled.'
                    : 'Only whoever asked for this refund, or an approver, can cancel it.'),
            attributes: fn () => ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => $reason],
        );
    }

    /** The shape every refund endpoint returns. */
    public function present(Refund $refund, User $viewer, bool $withEvents = false): array
    {
        $refund->loadMissing([
            'client:id,client_name,dfid_number', 'payment:id,amount,payment_date,payment_method,status', 'invoice:id,invoice_number,title',
            'requestedBy:id,name', 'reviewedBy:id,name', 'decidedBy:id,name', 'processedBy:id,name', 'cancelledBy:id,name',
        ]);

        $iso = fn ($dt) => $dt?->toIso8601String();

        return [
            'id'            => $refund->id,
            'refund_number' => $refund->refund_number,
            'amount'        => number_format((float) $refund->amount, 2, '.', ''),
            'status'        => $refund->status,
            'status_label'  => $refund->status_label,
            'reason'        => $refund->reason,
            'method'        => $refund->method,
            'reference'     => $refund->reference,
            'client'        => $refund->client ? ['id' => $refund->client->id, 'name' => $refund->client->client_name, 'dfid' => $refund->client->dfid_number] : null,
            'payment'       => $refund->payment ? [
                'id' => $refund->payment->id, 'amount' => number_format((float) $refund->payment->amount, 2, '.', ''),
                'date' => $refund->payment->payment_date?->toDateString(), 'method' => $refund->payment->payment_method,
                'refundable' => number_format($this->refundable($refund->payment), 2, '.', ''),
            ] : null,
            'invoice'       => $refund->invoice ? ['id' => $refund->invoice->id, 'number' => $refund->invoice->invoice_number, 'title' => $refund->invoice->title] : null,
            'requested_by'  => $refund->requestedBy?->name,
            'requested_at'  => $iso($refund->created_at),
            'reviewed_by'   => $refund->reviewedBy?->name,
            'review_started_at' => $iso($refund->review_started_at),
            'decided_by'    => $refund->decidedBy?->name,
            'decided_at'    => $iso($refund->decided_at),
            'decision_note' => $refund->decision_note,
            'processed_by'  => $refund->processedBy?->name,
            'processing_at' => $iso($refund->processing_at),
            'completed_at'  => $iso($refund->completed_at),
            'cancelled_by'  => $refund->cancelledBy?->name,
            'cancelled_at'  => $iso($refund->cancelled_at),
            'cancel_reason' => $refund->cancel_reason,
            'is_own'        => (int) $refund->requested_by === (int) $viewer->id,
            'can'           => [
                'review'   => $this->policy->review($viewer, $refund),
                'approve'  => $this->policy->approve($viewer, $refund),
                'reject'   => $this->policy->reject($viewer, $refund),
                'process'  => $this->policy->process($viewer, $refund),
                'complete' => $this->policy->complete($viewer, $refund),
                'cancel'   => $this->policy->cancel($viewer, $refund),
            ],
            'events'        => $withEvents ? $refund->events()->with('user:id,name')->get()->map(fn (RefundEvent $e) => [
                'from'       => $e->from_status,
                'to'         => $e->to_status,
                'to_label'   => Refund::LABELS[$e->to_status] ?? $e->to_status,
                'by'         => $e->user?->name,
                'note'       => $e->note,
                'meta'       => $e->meta,
                'at'         => $iso($e->created_at),
            ])->all() : null,
        ];
    }

    /**
     * One step along the state machine, under a lock.
     *
     * @param  Closure(Refund):void          $guard       runs on the locked row, before anything changes
     * @param  Closure(Refund):array         $attributes  what else the step records
     * @param  (Closure(Refund):void)|null   $after       runs after the step, in the same transaction
     */
    private function move(Refund $refund, string $to, User $actor, ?string $note, Closure $guard, Closure $attributes, array $meta = [], ?Closure $after = null): Refund
    {
        return DB::transaction(function () use ($refund, $to, $actor, $note, $guard, $attributes, $meta, $after) {
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (!$refund->canMoveTo($to)) {
                $this->refuse('status', "This refund is " . strtolower($refund->status_label) . ' and can\'t become ' . strtolower(Refund::LABELS[$to]) . ' now.');
            }

            $guard($refund);

            $from = $refund->status;
            $refund->update(['status' => $to] + $attributes($refund));
            $this->record($refund, $from, $to, $actor, $note, $meta);
            $this->activityLog->log('Refund', Refund::LABELS[$to], $refund->client_id, ['status' => $from], [
                'refund' => $refund->refund_number, 'status' => $to, 'note' => $note,
            ] + $meta);

            if ($after) {
                $after($refund);
            }

            return $refund->fresh();
        });
    }

    /**
     * Once money has actually gone back, the charge it was paid against is
     * re-derived from what it still holds. If every taka is back with the
     * client, the charge is closed as Refunded rather than left looking unpaid.
     */
    private function settleCharge(Refund $refund, User $actor): void
    {
        if (!$refund->invoice_id || !($charge = Invoice::whereKey($refund->invoice_id)->lockForUpdate()->first())) {
            return;
        }

        $this->invoices->recalculateStatus($charge);
        $charge->refresh();

        if (!$charge->isTerminal() && $charge->paid_amount <= 0 && $charge->refunded_amount > 0) {
            $charge->update(['status' => Invoice::STATUS_REFUNDED]);
            $this->activityLog->log('Invoice', 'Refunded', $charge->client_id, null, [
                'invoice_number' => $charge->invoice_number, 'refund' => $refund->refund_number,
            ]);
        }
    }

    private function record(Refund $refund, ?string $from, string $to, User $actor, ?string $note, array $meta = []): void
    {
        RefundEvent::create([
            'refund_id'   => $refund->id,
            'from_status' => $from,
            'to_status'   => $to,
            'user_id'     => $actor->id,
            'note'        => $note,
            'meta'        => $meta ?: null,
        ]);
    }

    private function notifyRequester(Refund $refund, User $actor, string $what): void
    {
        $requester = $refund->requested_by ? User::find($refund->requested_by) : null;

        if ($requester && (int) $requester->id !== (int) $actor->id && $requester->is_active) {
            $requester->notify(new RefundNeedsAttention($refund, $what));
        }
    }

    /** Why a decision was refused — your own request is the case people hit. */
    private function decisionRefusal(User $actor, Refund $refund): string
    {
        return (int) $refund->requested_by === (int) $actor->id
            ? 'You asked for this refund, so someone else has to decide on it.'
            : 'You are not allowed to decide on refunds.';
    }

    /** @throws AuthorizationException */
    private function allow(bool $allowed, string $message): void
    {
        if (!$allowed) {
            throw new AuthorizationException($message);
        }
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
