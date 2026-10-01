<?php

namespace App\Services;

use App\Models\AdvertisingExpenditure;
use App\Models\Brand;
use App\Models\PendingChange;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording spend against a brand's advertising budget. Corrections follow
 * PaymentService's own requestUpdate/requestDelete/approveChange/rejectChange
 * shape exactly (see app/Services/PaymentService.php:105-232) — the same
 * PendingChange-backed "a pending edit never touches the live row" rule, so
 * Brand::advertisingSpent() only ever reflects approved amounts.
 */
class AdvertisingExpenditureService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly ChangeApprovalService $changeApproval,
    ) {}

    /**
     * @throws ValidationException  no available budget, or a likely duplicate awaiting confirmation
     */
    public function create(Brand $brand, array $data, User $actor): AdvertisingExpenditure
    {
        $this->refuseIfBudgetUnavailable($brand);

        // Idempotency: a retried request (network timeout, double submit)
        // carrying the same client-generated key returns the original row
        // rather than inserting a second one — silent, no error.
        if (!empty($data['idempotency_key'])) {
            $existing = AdvertisingExpenditure::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
        }

        // Soft duplicate guard: a human plausibly re-entering the same
        // thing under a fresh key — separate from idempotency above, and
        // only blocks until explicitly re-confirmed.
        if (empty($data['confirm_duplicate']) && $this->looksLikeADuplicate($brand, $data)) {
            throw ValidationException::withMessages([
                'amount' => 'This looks like the same expenditure recorded moments ago (same brand, campaign, date and amount). Resend with confirmation if it is genuinely separate.',
            ]);
        }

        return DB::transaction(function () use ($brand, $data, $actor) {
            try {
                $expenditure = AdvertisingExpenditure::create([
                    'brand_id'        => $brand->id,
                    'ad_campaign_id'  => $data['ad_campaign_id'] ?? null,
                    'amount'          => $data['amount'],
                    'reporting_date'  => $data['reporting_date'],
                    'note'            => $data['note'] ?? null,
                    'recorded_by'     => $actor->id,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Lost a race against another request carrying the exact same
                // idempotency_key — the promise above ("silent, no error")
                // still holds: return the row the winner just inserted.
                if (!empty($data['idempotency_key'])) {
                    return AdvertisingExpenditure::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
                }

                throw $e;
            }

            $this->activityLog->log('Advertising Expenditure', 'Recorded', $brand->client_id, null, [
                'brand' => $brand->name, 'amount' => (string) $expenditure->amount, 'reporting_date' => $expenditure->reporting_date->toDateString(),
            ]);

            return $expenditure;
        });
    }

    private function refuseIfBudgetUnavailable(Brand $brand): void
    {
        if (!$brand->hasAvailableAdvertisingBudget()) {
            $this->refuse('brand', "{$brand->name}'s advertising budget isn't currently available — it may have been refunded or the charge cancelled. A Manager has to resolve that before new expenditure can be recorded.");
        }
    }

    private function looksLikeADuplicate(Brand $brand, array $data): bool
    {
        return AdvertisingExpenditure::where('brand_id', $brand->id)
            ->where('ad_campaign_id', $data['ad_campaign_id'] ?? null)
            ->whereDate('reporting_date', $data['reporting_date'])
            ->where('amount', $data['amount'])
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();
    }

    // ── Corrections ──────────────────────────────────────────────────────

    public const CORRECTABLE_FIELDS = ['amount', 'reporting_date', 'ad_campaign_id', 'note'];

    public const FIELD_LABELS = [
        'amount' => 'Amount', 'reporting_date' => 'Reporting date', 'ad_campaign_id' => 'Campaign', 'note' => 'Note',
    ];

    /**
     * @return array{applied:bool, change:PendingChange, expenditure:AdvertisingExpenditure}
     *
     * @throws ValidationException
     */
    public function requestUpdate(AdvertisingExpenditure $expenditure, array $data, string $reason, User $actor): array
    {
        $requested = collect($data)->only(self::CORRECTABLE_FIELDS)->all();

        return DB::transaction(function () use ($expenditure, $requested, $reason, $actor) {
            $expenditure = $this->lock($expenditure->id);
            $this->refuseIfWaiting($expenditure);

            $before  = $this->snapshot($expenditure, array_keys($requested));
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
                $expenditure->update($new);
                $this->activityLog->log('Advertising Expenditure', 'Updated', $expenditure->brand->client_id, $old, $new);
                $change = $this->record($expenditure, $old, $new, $reason, $actor, PendingChange::STATUS_APPLIED);

                return ['applied' => true, 'change' => $change, 'expenditure' => $expenditure->fresh()];
            }

            $change = $this->record($expenditure, $old, $new, $reason, $actor, PendingChange::STATUS_PENDING);
            $this->activityLog->log('Advertising Expenditure', 'Change Requested', $expenditure->brand->client_id, $old, $new + ['reason' => $reason]);
            DB::afterCommit(fn () => $this->changeApproval->notifyApprovers($change));

            return ['applied' => false, 'change' => $change, 'expenditure' => $expenditure];
        });
    }

    /** @return array{applied:bool, change:PendingChange} */
    public function requestDelete(AdvertisingExpenditure $expenditure, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($expenditure, $reason, $actor) {
            $expenditure = $this->lock($expenditure->id);
            $this->refuseIfWaiting($expenditure);

            $old = $this->snapshot($expenditure, self::CORRECTABLE_FIELDS);
            $new = [PendingChange::ACTION_KEY => PendingChange::ACTION_DELETE];

            if ($this->changeApproval->isPrivileged($actor)) {
                $change = $this->record($expenditure, $old, $new, $reason, $actor, PendingChange::STATUS_APPLIED);
                $this->activityLog->log('Advertising Expenditure', 'Deleted', $expenditure->brand->client_id, $old, []);
                $expenditure->delete();

                return ['applied' => true, 'change' => $change];
            }

            $change = $this->record($expenditure, $old, $new, $reason, $actor, PendingChange::STATUS_PENDING);
            $this->activityLog->log('Advertising Expenditure', 'Deletion Requested', $expenditure->brand->client_id, $old, ['reason' => $reason]);
            DB::afterCommit(fn () => $this->changeApproval->notifyApprovers($change));

            return ['applied' => false, 'change' => $change];
        });
    }

    /** @throws ValidationException|AuthorizationException */
    public function approveChange(PendingChange $change, User $approver, ?string $note = null): PendingChange
    {
        return DB::transaction(function () use ($change, $approver, $note) {
            $change = $this->lockForReview($change, $approver);

            $expenditure = AdvertisingExpenditure::whereKey($change->model_id)->lockForUpdate()->first();
            if (!$expenditure) {
                $this->refuse('change', 'This expenditure no longer exists. Reject the request instead.');
            }

            $now = $this->snapshot($expenditure, array_keys(array_diff_key($change->old_values, [PendingChange::ACTION_KEY => true])));
            foreach ($change->old_values as $field => $value) {
                if (array_key_exists($field, $now) && $now[$field] !== $this->normalize($field, $value)) {
                    $this->refuse('change', 'This expenditure has changed since the request was made (' . (self::FIELD_LABELS[$field] ?? $field)
                        . '). Reject it and ask for a fresh request against the current values.');
                }
            }

            if ($change->isDeletion()) {
                $this->activityLog->log('Advertising Expenditure', 'Deleted', $expenditure->brand->client_id, $now, []);
                $expenditure->delete();
            } else {
                $old = $expenditure->only(array_keys($change->new_values));
                $expenditure->update($change->new_values);
                $this->activityLog->log('Advertising Expenditure', 'Updated', $expenditure->brand->client_id, $old, $change->new_values);
            }

            $change->update([
                'status' => PendingChange::STATUS_APPROVED, 'reviewed_by' => $approver->id,
                'reviewed_at' => now(), 'applied_at' => now(), 'review_note' => $note,
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
                'status' => PendingChange::STATUS_REJECTED, 'reviewed_by' => $approver->id,
                'reviewed_at' => now(), 'review_note' => $note,
            ]);

            $expenditure = AdvertisingExpenditure::find($change->model_id);
            $this->activityLog->log('Advertising Expenditure', 'Change Rejected', $expenditure?->brand->client_id, $change->old_values, $change->new_values + ['note' => $note]);

            return $change->fresh();
        });
    }

    private function lock(int $id): AdvertisingExpenditure
    {
        return AdvertisingExpenditure::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function refuseIfWaiting(AdvertisingExpenditure $expenditure): void
    {
        if (PendingChange::for(AdvertisingExpenditure::class, $expenditure->id)->pending()->lockForUpdate()->exists()) {
            $this->refuse('expenditure', 'A change to this expenditure is already waiting for approval. It has to be approved or rejected before another can be requested.');
        }
    }

    private function lockForReview(PendingChange $change, User $approver): PendingChange
    {
        $change = PendingChange::whereKey($change->id)->lockForUpdate()->firstOrFail();

        if ($change->model_type !== AdvertisingExpenditure::class) {
            throw new \InvalidArgumentException('Not an advertising expenditure change.');
        }
        if ($change->status !== PendingChange::STATUS_PENDING) {
            $this->refuse('change', 'This change has already been reviewed.');
        }
        if (!$this->changeApproval->isPrivileged($approver)) {
            throw new AuthorizationException('Only a Super Admin or Manager can review expenditure changes.');
        }
        if ((int) $change->requested_by === (int) $approver->id) {
            throw new AuthorizationException('You cannot review your own request. Another approver has to.');
        }

        return $change;
    }

    private function record(AdvertisingExpenditure $expenditure, array $old, array $new, string $reason, User $actor, string $status): PendingChange
    {
        $applied = $status === PendingChange::STATUS_APPLIED;

        return PendingChange::create([
            'model_type'   => AdvertisingExpenditure::class,
            'model_id'     => $expenditure->id,
            'old_values'   => ['brand_id' => (int) $expenditure->brand_id] + $old,
            'new_values'   => $new,
            'reason'       => $reason,
            'requested_by' => $actor->id,
            'status'       => $status,
            'reviewed_by'  => $applied ? $actor->id : null,
            'reviewed_at'  => $applied ? now() : null,
            'applied_at'   => $applied ? now() : null,
        ]);
    }

    private function snapshot(AdvertisingExpenditure $expenditure, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->normalize($field, $expenditure->getAttribute($field));
        }

        return $values;
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($field) {
            'amount'         => number_format(round((float) $value, 2), 2, '.', ''),
            'ad_campaign_id' => (int) $value,
            'reporting_date' => \Illuminate\Support\Carbon::parse($value)->toDateString(),
            default          => (string) $value,
        };
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
