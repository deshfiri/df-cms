<?php

namespace App\Services;

use App\Models\BrandChecklist;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clearing a hold App\Observers\InvoiceObserver set (Fix I of the SRS
 * integration plan). Two distinct paths:
 *
 * - Revalidated clear: the checklist-creation trigger's own two conditions
 *   (now on Brand — see Brand::hasPaidAdvertisingBudgetInvoice()/
 *   hasActiveContentChargeInvoice(), the exact logic the observer itself
 *   uses) are checked again; if both are true, the hold lifts as resolved.
 * - Manual override: the condition is NOT actually resolved, but a Manager
 *   lifts the hold anyway. Requires a typed reason and is logged
 *   distinguishably from a genuine resolution, capturing that the
 *   underlying issue was still unresolved at override time.
 */
class BrandChecklistHoldService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /** @return array{cleared:bool, method:string} */
    public function clearHold(BrandChecklist $checklist, User $actor, ?string $overrideReason = null): array
    {
        return DB::transaction(function () use ($checklist, $actor, $overrideReason) {
            // Locked so two concurrent clear-hold requests against the same
            // checklist can't both pass the isOnHold() guard and both write
            // (and both log) — the loser re-reads it already cleared.
            $checklist = BrandChecklist::whereKey($checklist->id)->lockForUpdate()->firstOrFail();

            if (!$checklist->isOnHold()) {
                throw ValidationException::withMessages(['hold' => 'This checklist is not currently on hold.']);
            }

            $brand = $checklist->brand;
            $budgetPaid = $brand->hasPaidAdvertisingBudgetInvoice();
            $contentChargeActive = $brand->hasActiveContentChargeInvoice();
            $resolved = $budgetPaid && $contentChargeActive;

            if (!$resolved && !$overrideReason) {
                throw ValidationException::withMessages([
                    'reason' => "The underlying issue isn't actually resolved yet ({$checklist->on_hold_reason}). Clearing it anyway requires a typed reason.",
                ]);
            }

            $oldReason = $checklist->on_hold_reason;
            $checklist->update(['on_hold_at' => null, 'on_hold_reason' => null]);

            if ($resolved) {
                $this->activityLog->log('Brand Checklist Hold', 'Resolved', $brand->client_id, ['reason' => $oldReason], []);

                return ['cleared' => true, 'method' => 'resolved'];
            }

            $this->activityLog->log('Brand Checklist Hold', 'Manually cleared', $brand->client_id, ['reason' => $oldReason], [
                'override_reason'        => $overrideReason,
                'budget_paid'            => $budgetPaid,
                'content_charge_active'  => $contentChargeActive,
                'actor'                  => $actor->name,
            ]);

            return ['cleared' => true, 'method' => 'manual_override'];
        });
    }
}
