<?php

namespace App\Observers;

use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\Invoice;
use App\Services\ActivityLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a brand's checklist the moment it qualifies, and holds it
 * (without ever touching its content) the moment it stops qualifying — see
 * the SRS integration plan's checklist-creation trigger and Fix E.
 *
 * Deliberately a model observer rather than new logic inside PaymentService/
 * InvoiceService/RefundService: every one of those already calls
 * InvoiceService::recalculateStatus(), which touches the invoice's `status`
 * column and fires Eloquent's `updated` event whenever it actually changes —
 * so this hooks in without a single line of change to any existing payment
 * flow.
 */
class InvoiceObserver
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function created(Invoice $invoice): void
    {
        $this->evaluate($invoice);
    }

    public function updated(Invoice $invoice): void
    {
        if ($invoice->wasChanged('status')) {
            $this->evaluate($invoice);
        }
    }

    private function evaluate(Invoice $invoice): void
    {
        if (!$invoice->brand_id) {
            return;
        }

        $brand = $invoice->brand ?? Brand::find($invoice->brand_id);
        if (!$brand) {
            return;
        }

        DB::transaction(function () use ($brand) {
            $budgetPaid = $brand->hasPaidAdvertisingBudgetInvoice();
            // Locked so two concurrent evaluate() calls for the same brand
            // (e.g. its ad-budget payment and its content-charge invoice
            // landing within milliseconds of each other) can't both decide
            // "not on hold yet" and both write/log a hold, or both race past
            // the "no checklist yet" check below.
            $checklist = BrandChecklist::where('brand_id', $brand->id)->lockForUpdate()->first();

            if (!$checklist) {
                if ($budgetPaid && $brand->hasActiveContentChargeInvoice()) {
                    try {
                        BrandChecklist::create(['brand_id' => $brand->id]);
                    } catch (UniqueConstraintViolationException $e) {
                        // Lost the race — another concurrent evaluate() call
                        // already created it; nothing left to do here.
                    }
                }

                return;
            }

            if ($checklist->isOnHold()) {
                // A Manager clears an existing hold explicitly (Fix I) — this
                // observer only ever sets a hold, never lifts one on its own.
                return;
            }

            if (!$budgetPaid) {
                $this->hold($checklist, "The advertising-budget payment for {$brand->name} was refunded or reversed.");

                return;
            }

            if (!$brand->hasActiveContentChargeInvoice()) {
                $this->hold($checklist, "The content-charge invoice for {$brand->name} was cancelled.");
            }
        });
    }

    private function hold(BrandChecklist $checklist, string $reason): void
    {
        $checklist->update(['on_hold_at' => now(), 'on_hold_reason' => $reason]);
        $this->activityLog->log('Brand Checklist Hold', 'Held', $checklist->brand->client_id, null, ['reason' => $reason]);
    }
}
