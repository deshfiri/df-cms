<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BrandIntegration;
use App\Models\Client;
use App\Models\PlatformAd;
use App\Models\PlatformAdSet;
use App\Models\PlatformCampaign;
use App\Services\MarketingAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Marketing section: brand picker, ads dashboard, campaign/ad-set/ad lists.
 *
 * Every endpoint authorises against the brand before reading anything, so the
 * brand id in a URL is a request, not a grant.
 */
class MarketingController extends Controller
{
    public function __construct(
        private readonly MarketingAnalyticsService $analytics,
    ) {}

    /** Landing page: pick a brand — or open a new one for any client. */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Client::class);

        $brands = $this->visibleBrands($request);

        // A brand belongs to a client, but creating one was only ever reachable
        // from that client's profile. Anyone who manages ads may open a brand
        // for any client they can see, so the picker is offered here too.
        $canManage = $request->user()->can('manage ads');

        return view('marketing.index', [
            'brands'    => $brands,
            'presets'   => MarketingAnalyticsService::presets(),
            'canManage' => $canManage,
            'clients'   => $canManage
                ? Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number'])
                : collect(),
        ]);
    }

    /** Ads dashboard for one brand. */
    public function brand(Request $request, Brand $brand)
    {
        $this->authorize('view', $brand);

        $brand->load(['client:id,client_name', 'integrations', 'platformAdAccounts']);

        return view('marketing.brand', [
            'brand'      => $brand,
            'brands'     => $this->visibleBrands($request),
            'presets'    => MarketingAnalyticsService::presets(),
            'adAccounts' => $brand->platformAdAccounts,
            'meta'       => $brand->integrationFor(BrandIntegration::PLATFORM_META),
        ]);
    }

    /** KPIs, chart series and the campaign table for the current filters. */
    public function dashboardData(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $filters = $request->validate([
            'range'         => ['nullable', 'string'],
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date'],
            'ad_account_id' => ['nullable', 'integer'],
        ]);

        $range = $this->analytics->resolveRange(
            $filters['range'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );

        $adAccountId = $this->ownedAdAccountId($brand, $filters['ad_account_id'] ?? null);

        return response()->json([
            'range' => [
                'label' => $range['label'],
                'from'  => $range['from']->toDateString(),
                'to'    => $range['to']->toDateString(),
            ],
            'summary'   => $this->analytics->summary($brand, $range['from'], $range['to'], $adAccountId),
            // Reported alongside, never merged — see MarketingAnalyticsService.
            'manual'    => $this->analytics->manualSummary($brand, $range['from'], $range['to']),
            'campaigns' => $this->analytics->campaignPerformance($brand, $range['from'], $range['to'], $adAccountId),
            'series'    => $this->analytics->timeSeries($brand, $range['from'], $range['to'], $adAccountId),
        ]);
    }

    /** Browse the synced hierarchy: campaigns → ad sets → ads. */
    public function browse(Request $request, Brand $brand)
    {
        $this->authorize('view', $brand);

        $brand->load(['client:id,client_name', 'platformAdAccounts']);

        return view('marketing.browse', [
            'brand'      => $brand,
            'adAccounts' => $brand->platformAdAccounts,
        ]);
    }

    /** Synced campaigns for a brand. */
    public function campaigns(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $campaigns = PlatformCampaign::where('brand_id', $brand->id)
            ->when($this->ownedAdAccountId($brand, $request->integer('ad_account_id') ?: null),
                fn ($q, $id) => $q->where('platform_ad_account_id', $id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->with('adAccount:id,name,currency')
            ->orderBy('name')
            ->paginate(25);

        return response()->json($campaigns);
    }

    /** Ad sets, optionally within one campaign. */
    public function adSets(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $adSets = PlatformAdSet::where('brand_id', $brand->id)
            ->when($request->filled('campaign_id'), function ($q) use ($request, $brand) {
                // Scoped to the brand so a campaign id from elsewhere finds nothing.
                $q->whereHas('campaign', fn ($c) => $c
                    ->where('id', $request->integer('campaign_id'))
                    ->where('brand_id', $brand->id));
            })
            ->with('campaign:id,name')
            ->orderBy('name')
            ->paginate(25);

        return response()->json($adSets);
    }

    /** Ads, optionally within one ad set. */
    public function ads(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $ads = PlatformAd::where('brand_id', $brand->id)
            ->when($request->filled('ad_set_id'), function ($q) use ($request, $brand) {
                $q->whereHas('adSet', fn ($s) => $s
                    ->where('id', $request->integer('ad_set_id'))
                    ->where('brand_id', $brand->id));
            })
            ->with('adSet:id,name')
            ->orderBy('name')
            ->paginate(25);

        return response()->json($ads);
    }

    /**
     * Brands this user may see, grouped by client for the picker.
     *
     * Filtered by the same client policy the rest of the app uses, so the
     * picker cannot offer a brand the user would be refused anyway.
     */
    private function visibleBrands(Request $request)
    {
        return Brand::query()
            ->active()
            ->with('client:id,client_name')
            ->orderBy('name')
            ->get()
            ->filter(fn (Brand $brand) => $request->user()->can('view', $brand))
            ->values();
    }

    /**
     * Accept an ad account id only if it belongs to this brand.
     *
     * Without this check the filter would be a way to read another brand's
     * numbers through a brand you *can* see.
     */
    private function ownedAdAccountId(Brand $brand, ?int $adAccountId): ?int
    {
        if (!$adAccountId) {
            return null;
        }

        return $brand->platformAdAccounts()->whereKey($adAccountId)->value('id');
    }
}
