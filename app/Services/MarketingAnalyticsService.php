<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\PlatformAdInsight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads the synced insight rows into the numbers a dashboard shows.
 *
 * Two rules run through all of it:
 *
 *  - Rates are recomputed from totals, never averaged. Averaging seven daily
 *    CTRs gives a different (wrong) answer to total clicks ÷ total impressions.
 *  - A metric with no data is null, not zero. "No conversions recorded" and
 *    "zero conversions" look identical as 0 and mean very different things, and
 *    the brief was explicit about not fabricating values.
 */
class MarketingAnalyticsService
{
    /** @return array<string,array{label:string,from:Carbon,to:Carbon}> */
    public static function presets(): array
    {
        return [
            'today'      => ['label' => 'Today',        'from' => now()->startOfDay(),               'to' => now()->endOfDay()],
            'yesterday'  => ['label' => 'Yesterday',    'from' => now()->subDay()->startOfDay(),     'to' => now()->subDay()->endOfDay()],
            'last_7'     => ['label' => 'Last 7 Days',  'from' => now()->subDays(6)->startOfDay(),   'to' => now()->endOfDay()],
            'last_30'    => ['label' => 'Last 30 Days', 'from' => now()->subDays(29)->startOfDay(),  'to' => now()->endOfDay()],
            'this_month' => ['label' => 'This Month',   'from' => now()->startOfMonth(),             'to' => now()->endOfMonth()],
            'last_month' => ['label' => 'Last Month',   'from' => now()->subMonth()->startOfMonth(), 'to' => now()->subMonth()->endOfMonth()],
        ];
    }

    /** @return array{from:Carbon,to:Carbon,label:string} */
    public function resolveRange(?string $preset, ?string $from, ?string $to): array
    {
        if ($preset === 'custom' && $from && $to) {
            return [
                'from'  => Carbon::parse($from)->startOfDay(),
                'to'    => Carbon::parse($to)->endOfDay(),
                'label' => 'Custom Range',
            ];
        }

        $presets = self::presets();
        $chosen = $presets[$preset] ?? $presets['last_30'];

        return ['from' => $chosen['from'], 'to' => $chosen['to'], 'label' => $chosen['label']];
    }

    /**
     * Headline KPIs for a brand over a range.
     *
     * @return array<string,mixed>
     */
    public function summary(Brand $brand, Carbon $from, Carbon $to, ?int $adAccountId = null): array
    {
        $totals = $this->baseQuery($brand, $from, $to, $adAccountId)
            ->selectRaw('
                SUM(spend) as spend,
                SUM(impressions) as impressions,
                SUM(reach) as reach,
                SUM(clicks) as clicks,
                SUM(conversions) as conversions,
                SUM(conversion_value) as conversion_value,
                COUNT(*) as rows_counted
            ')
            ->first();

        // No rows at all means nothing has been synced for this range yet —
        // report that honestly rather than a wall of zeros.
        if (!$totals || (int) $totals->rows_counted === 0) {
            return ['has_data' => false] + array_fill_keys([
                'spend', 'impressions', 'reach', 'clicks', 'ctr', 'cpc', 'cpm',
                'conversions', 'conversion_value', 'cost_per_conversion', 'roas',
            ], null);
        }

        return [
            'has_data'            => true,
            'spend'               => (float) $totals->spend,
            'impressions'         => (int) $totals->impressions,
            'reach'               => (int) $totals->reach,
            'clicks'              => (int) $totals->clicks,
            'conversions'         => (int) $totals->conversions,
            'conversion_value'    => (float) $totals->conversion_value,
            'ctr'                 => $this->rate($totals->clicks, $totals->impressions, 100),
            'cpc'                 => $this->rate($totals->spend, $totals->clicks),
            'cpm'                 => $this->rate($totals->spend, $totals->impressions, 1000),
            'cost_per_conversion' => $this->rate($totals->spend, $totals->conversions),
            'roas'                => $this->rate($totals->conversion_value, $totals->spend),
            'currency'            => $this->currency($brand, $adAccountId),
        ];
    }

    /**
     * Per-campaign performance table.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function campaignPerformance(Brand $brand, Carbon $from, Carbon $to, ?int $adAccountId = null): Collection
    {
        return $this->baseQuery($brand, $from, $to, $adAccountId)
            ->join('platform_campaigns', 'platform_campaigns.id', '=', 'platform_ad_insights.platform_campaign_id')
            ->groupBy('platform_campaigns.id', 'platform_campaigns.name', 'platform_campaigns.status', 'platform_campaigns.objective')
            ->selectRaw('
                platform_campaigns.id as campaign_id,
                platform_campaigns.name as campaign,
                platform_campaigns.status,
                platform_campaigns.objective,
                SUM(spend) as spend,
                SUM(impressions) as impressions,
                SUM(reach) as reach,
                SUM(clicks) as clicks,
                SUM(conversions) as conversions,
                SUM(conversion_value) as conversion_value
            ')
            ->orderByDesc('spend')
            ->get()
            ->map(fn ($row) => [
                'campaign_id'      => $row->campaign_id,
                'campaign'         => $row->campaign,
                'status'           => $row->status,
                'objective'        => $row->objective,
                'spend'            => (float) $row->spend,
                'impressions'      => (int) $row->impressions,
                'reach'            => (int) $row->reach,
                'clicks'           => (int) $row->clicks,
                'conversions'      => (int) $row->conversions,
                'conversion_value' => (float) $row->conversion_value,
                'ctr'              => $this->rate($row->clicks, $row->impressions, 100),
                'cpc'              => $this->rate($row->spend, $row->clicks),
                'cpm'              => $this->rate($row->spend, $row->impressions, 1000),
                'cpa'              => $this->rate($row->spend, $row->conversions),
                'roas'             => $this->rate($row->conversion_value, $row->spend),
            ]);
    }

    /**
     * Daily series for the charts, with empty days filled in so a gap in
     * spending reads as a trough rather than a missing point.
     *
     * @return array{labels:array<int,string>,series:array<string,array<int,float|int|null>>}
     */
    public function timeSeries(Brand $brand, Carbon $from, Carbon $to, ?int $adAccountId = null): array
    {
        $rows = $this->baseQuery($brand, $from, $to, $adAccountId)
            ->groupBy('date')
            ->selectRaw('
                date,
                SUM(spend) as spend,
                SUM(impressions) as impressions,
                SUM(reach) as reach,
                SUM(clicks) as clicks,
                SUM(conversions) as conversions,
                SUM(conversion_value) as conversion_value
            ')
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        $labels = [];
        $series = array_fill_keys(
            ['spend', 'impressions', 'reach', 'clicks', 'conversions', 'conversion_value', 'ctr', 'cpc', 'cpm', 'roas'],
            []
        );

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $row = $rows->get($key);

            $labels[] = $day->format('d M');

            $series['spend'][]            = (float) ($row->spend ?? 0);
            $series['impressions'][]      = (int) ($row->impressions ?? 0);
            $series['reach'][]            = (int) ($row->reach ?? 0);
            $series['clicks'][]           = (int) ($row->clicks ?? 0);
            $series['conversions'][]      = (int) ($row->conversions ?? 0);
            $series['conversion_value'][] = (float) ($row->conversion_value ?? 0);
            $series['ctr'][]  = $row ? $this->rate($row->clicks, $row->impressions, 100) : null;
            $series['cpc'][]  = $row ? $this->rate($row->spend, $row->clicks) : null;
            $series['cpm'][]  = $row ? $this->rate($row->spend, $row->impressions, 1000) : null;
            $series['roas'][] = $row ? $this->rate($row->conversion_value, $row->spend) : null;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Hand-entered figures for the same brand and range.
     *
     * The manual ads module predates the API integration and is still in use.
     * Its numbers are reported separately rather than added to the synced
     * totals: mixing a hand-typed spend into a Meta-reported one would produce
     * a figure that reconciles with neither source.
     *
     * @return array<string,mixed>
     */
    public function manualSummary(Brand $brand, Carbon $from, Carbon $to): array
    {
        $totals = \App\Models\AdCampaignDailyReport::query()
            ->join('ad_campaigns', 'ad_campaigns.id', '=', 'ad_campaign_daily_reports.ad_campaign_id')
            ->where('ad_campaigns.brand_id', $brand->id)
            // The manual campaign list hides soft-deleted rows; so does this.
            ->whereNull('ad_campaigns.deleted_at')
            // whereDate, not whereBetween: the `date` cast can store a full
            // datetime ("2026-08-22 00:00:00"), which sorts after the plain
            // date string and silently drops the newest day.
            ->whereDate('ad_campaign_daily_reports.report_date', '>=', $from->toDateString())
            ->whereDate('ad_campaign_daily_reports.report_date', '<=', $to->toDateString())
            ->selectRaw('
                SUM(ad_campaign_daily_reports.spend) as spend,
                SUM(ad_campaign_daily_reports.sales) as sales,
                SUM(ad_campaign_daily_reports.leads) as leads,
                SUM(ad_campaign_daily_reports.orders) as orders,
                COUNT(*) as rows_counted
            ')
            ->first();

        if (!$totals || (int) $totals->rows_counted === 0) {
            return ['has_data' => false];
        }

        return [
            'has_data' => true,
            'spend'    => (float) $totals->spend,
            'sales'    => (float) $totals->sales,
            'leads'    => (int) $totals->leads,
            'orders'   => (int) $totals->orders,
            'roas'     => $this->rate($totals->sales, $totals->spend),
            'days'     => (int) $totals->rows_counted,
        ];
    }

    private function baseQuery(Brand $brand, Carbon $from, Carbon $to, ?int $adAccountId)
    {
        $query = PlatformAdInsight::query()
            ->where('platform_ad_insights.brand_id', $brand->id)
            ->where('platform_ad_insights.level', PlatformAdInsight::LEVEL_CAMPAIGN)
            // See manualSummary(): a cast date can carry a time component, so
            // compare on the date part rather than as strings.
            ->whereDate('platform_ad_insights.date', '>=', $from->toDateString())
            ->whereDate('platform_ad_insights.date', '<=', $to->toDateString());

        if ($adAccountId) {
            $query->where('platform_ad_insights.platform_ad_account_id', $adAccountId);
        }

        return $query;
    }

    /** A rate is null when its denominator is zero — not zero, and never a divide by zero. */
    private function rate(mixed $numerator, mixed $denominator, float $multiplier = 1): ?float
    {
        $denominator = (float) $denominator;

        if ($denominator <= 0) {
            return null;
        }

        return round(((float) $numerator / $denominator) * $multiplier, 4);
    }

    private function currency(Brand $brand, ?int $adAccountId): ?string
    {
        return $brand->platformAdAccounts()
            ->when($adAccountId, fn ($q) => $q->where('id', $adAccountId))
            ->value('currency');
    }
}
