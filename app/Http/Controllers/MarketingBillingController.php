<?php

namespace App\Http\Controllers;

use App\Models\AdvertisingExpenditure;
use App\Models\Brand;
use App\Models\PaymentCategory;
use App\Models\PendingChange;
use App\Models\PublishedContent;
use App\Services\ActivityLogService;
use App\Services\AdvertisingExpenditureService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Marketing Panel's own additions (Phase 3): content-charge entry,
 * advertising expenditure, and publishing review. Kept separate from
 * MarketingController, which stays focused on the ads/analytics dashboard.
 */
class MarketingBillingController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AdvertisingExpenditureService $expenditures,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * A content-charge Invoice only — never touches PaymentService, so
     * `manage content-charges` can never record money received. Brand comes
     * from the route's own resolved model, not a free-choice picker, so the
     * "which brand is this for" ambiguity Fix J solves doesn't apply here —
     * there is no separate client/brand input to mismatch.
     */
    public function storeContentCharge(Request $request, Brand $brand): JsonResponse
    {
        abort_unless($request->user()->can('manage content-charges'), 403);

        $data = $request->validate([
            'total_payable' => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'title'         => ['nullable', 'string', 'max:200'],
            'due_date'      => ['nullable', 'date'],
        ]);

        $invoice = $this->invoices->create($brand->client, [
            'brand_id'             => $brand->id,
            'payment_category_id'  => PaymentCategory::where('name', PaymentCategory::NAME_CONTENT_CHARGE)->value('id'),
            'total_payable'        => $data['total_payable'],
            'title'                => $data['title'] ?? null,
            'due_date'             => $data['due_date'] ?? null,
        ], $request->user());

        return response()->json(['success' => true, 'data' => $this->invoices->present($invoice->load('category'))]);
    }

    /** Every Content Production charge billed against this brand — same shape InvoiceService::present() already gives the Payments tab. */
    public function contentCharges(Request $request, Brand $brand): JsonResponse
    {
        abort_unless($request->user()->can('manage content-charges'), 403);

        $invoices = $brand->invoices()
            ->whereHas('category', fn ($q) => $q->where('name', PaymentCategory::NAME_CONTENT_CHARGE))
            ->with('category')
            ->latest('issued_date')
            ->get()
            ->map(fn ($invoice) => $this->invoices->present($invoice));

        return response()->json(['data' => $invoices]);
    }

    public function budget(Request $request, Brand $brand): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['manage advertising-expenditure', 'view brand-checklist-overview']), 403);

        $checklist = $brand->checklist;

        return response()->json([
            'budget'                  => $brand->advertisingBudget(),
            'spent'                   => $brand->advertisingSpent(),
            'remaining'               => $brand->advertisingRemaining(),
            'is_overspent'            => $brand->isAdvertisingOverspent(),
            'overspent_amount'        => $brand->advertisingOverspentAmount(),
            'has_available_budget'    => $brand->hasAvailableAdvertisingBudget(),
            // Read-only workflow context (Fix H/I) — never mutated from this endpoint.
            'checklist_status'        => !$checklist ? 'not_eligible' : ($checklist->isOnHold() ? 'on_hold' : 'active'),
            'checklist_hold_reason'   => $checklist?->on_hold_reason,
        ]);
    }

    /** This brand's recorded expenditure, each flagged if a correction/deletion is already awaiting approval. */
    public function expenditureHistory(Request $request, Brand $brand): JsonResponse
    {
        abort_unless($request->user()->can('manage advertising-expenditure'), 403);

        $expenditureIds = $brand->advertisingExpenditures()->pluck('id');
        $pendingIds = PendingChange::where('model_type', AdvertisingExpenditure::class)
            ->pending()
            ->whereIn('model_id', $expenditureIds)
            ->pluck('model_id');

        $expenditures = $brand->advertisingExpenditures()
            ->with(['recordedBy:id,name', 'adCampaign:id,name'])
            ->latest('reporting_date')
            ->get()
            ->map(fn (AdvertisingExpenditure $e) => [
                'id'                 => $e->id,
                'amount'             => (float) $e->amount,
                'reporting_date'     => $e->reporting_date->toDateString(),
                'note'               => $e->note,
                'ad_campaign'        => $e->adCampaign?->name,
                'recorded_by'        => $e->recordedBy?->name,
                'created_at'         => $e->created_at->toDateString(),
                'has_pending_change' => $pendingIds->contains($e->id),
            ]);

        return response()->json(['data' => $expenditures]);
    }

    public function storeExpenditure(Request $request, Brand $brand): JsonResponse
    {
        abort_unless($request->user()->can('manage advertising-expenditure'), 403);

        $data = $request->validate([
            'amount'            => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'reporting_date'    => ['required', 'date'],
            'ad_campaign_id'    => ['nullable', 'integer', Rule::exists('ad_campaigns', 'id')->where('brand_id', $brand->id)],
            'note'              => ['nullable', 'string', 'max:1000'],
            'idempotency_key'   => ['nullable', 'string', 'max:64'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ]);

        $expenditure = $this->expenditures->create($brand, $data, $request->user());

        return response()->json([
            'success' => true,
            'data'    => $expenditure,
            'budget'  => [
                'budget'       => $brand->advertisingBudget(),
                'spent'        => $brand->advertisingSpent(),
                'remaining'    => $brand->advertisingRemaining(),
                'is_overspent' => $brand->isAdvertisingOverspent(),
            ],
        ]);
    }

    /** Corrections queue into the existing Pending Changes review — see PendingChangeController. */
    public function requestExpenditureUpdate(Request $request, Brand $brand, AdvertisingExpenditure $expenditure): JsonResponse
    {
        abort_unless($request->user()->can('manage advertising-expenditure'), 403);
        abort_if((int) $expenditure->brand_id !== (int) $brand->id, 404);

        $data = $request->validate([
            'amount'         => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999'],
            'reporting_date' => ['sometimes', 'date'],
            'ad_campaign_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ad_campaigns', 'id')->where('brand_id', $brand->id)],
            'note'           => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $reason = $this->reason($request);

        $result = $this->expenditures->requestUpdate($expenditure, $data, $reason, $request->user());

        return $this->changeResponse($result, 'Expenditure updated.');
    }

    public function requestExpenditureDelete(Request $request, Brand $brand, AdvertisingExpenditure $expenditure): JsonResponse
    {
        abort_unless($request->user()->can('manage advertising-expenditure'), 403);
        abort_if((int) $expenditure->brand_id !== (int) $brand->id, 404);

        $result = $this->expenditures->requestDelete($expenditure, $this->reason($request), $request->user());

        return $this->changeResponse($result, 'Expenditure deleted.');
    }

    /** Every published item for this brand still awaiting Marketing's review. */
    public function unreviewedPublishedContents(Request $request, Brand $brand): JsonResponse
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $items = PublishedContent::where('brand_id', $brand->id)
            ->whereNull('reviewed_at')
            ->with(['item:id,title,category', 'publishedBy:id,name', 'submission:id,link_url,file_path'])
            ->orderBy('published_at')
            ->get();

        return response()->json(['data' => $items]);
    }

    /**
     * Marks one specific publish reviewed — never touches any other row
     * (see Fix F). reviewed_at/reviewed_by are the only mutable fields on
     * published_contents; every write to them is logged here, even though
     * the row itself only ever shows the current reviewer.
     */
    public function reviewPublishedContent(Request $request, Brand $brand, PublishedContent $publishedContent): JsonResponse
    {
        abort_unless($request->user()->can('manage publishing-review'), 403);
        abort_if((int) $publishedContent->brand_id !== (int) $brand->id, 404);

        $old = ['reviewed_at' => $publishedContent->reviewed_at?->toIso8601String(), 'reviewed_by' => $publishedContent->reviewedBy?->name];

        $publishedContent->update(['reviewed_at' => now(), 'reviewed_by' => $request->user()->id]);

        $this->activityLog->log('Publishing Review', 'Reviewed', $brand->client_id, $old, [
            'published_content_id' => $publishedContent->id, 'reviewed_by' => $request->user()->name,
        ]);

        return response()->json(['success' => true, 'data' => $publishedContent->fresh()->load('reviewedBy:id,name')]);
    }

    private function reason(Request $request): string
    {
        return $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Say why this expenditure is being changed — it is kept on the record.'],
        )['reason'];
    }

    /** @param array{applied:bool, change:\App\Models\PendingChange, expenditure?:AdvertisingExpenditure} $result */
    private function changeResponse(array $result, string $appliedMessage): JsonResponse
    {
        if ($result['applied']) {
            return response()->json([
                'success'     => true,
                'applied'     => true,
                'message'     => $appliedMessage,
                'expenditure' => $result['expenditure'] ?? null,
                'change_id'   => $result['change']->id,
            ]);
        }

        return response()->json([
            'success'   => true,
            'applied'   => false,
            'pending'   => true,
            'message'   => 'Sent for approval. A Super Admin or Manager has to approve it before it takes effect.',
            'change_id' => $result['change']->id,
        ], 202);
    }
}
