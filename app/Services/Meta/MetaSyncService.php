<?php

namespace App\Services\Meta;

use App\Models\BrandIntegration;
use App\Models\IntegrationResource;
use App\Models\PlatformAd;
use App\Models\PlatformAdAccount;
use App\Models\PlatformAdInsight;
use App\Models\PlatformAdSet;
use App\Models\PlatformCampaign;
use App\Models\SyncLog;
use Illuminate\Support\Carbon;

/**
 * Pulls a brand's Meta advertising data into the local tables.
 *
 * Everything is an upsert keyed on the platform's own id, so running this every
 * twenty minutes updates rows instead of stacking duplicates. Insights are
 * fetched for a short trailing window rather than all of history — Meta keeps
 * revising the last few days as conversions attribute late.
 */
class MetaSyncService
{
    private const PLATFORM = BrandIntegration::PLATFORM_META;

    /** Days of insights to re-pull each run, to catch late attribution. */
    private const INSIGHT_WINDOW_DAYS = 7;

    public function __construct(
        private readonly MetaApiService $api,
    ) {}

    /**
     * Sync one brand end to end.
     *
     * @return array{records:int}
     * @throws MetaApiException
     */
    public function syncIntegration(BrandIntegration $integration, SyncLog $log): array
    {
        $api = $this->api->withToken($integration->accessToken());
        $records = 0;

        foreach ($this->selectedAdAccounts($integration) as $resource) {
            $account = $this->upsertAdAccount($integration, $resource);
            $records++;

            $campaigns = $this->syncCampaigns($api, $account);
            $records += count($campaigns);

            foreach ($campaigns as $campaign) {
                $adSets = $this->syncAdSets($api, $campaign);
                $records += count($adSets);

                foreach ($adSets as $adSet) {
                    $records += count($this->syncAds($api, $adSet));
                }
            }

            $records += $this->syncInsights($api, $account);

            $account->update(['last_synced_at' => now()]);
        }

        $log->update(['records_processed' => $records]);

        return ['records' => $records];
    }

    /** @return \Illuminate\Support\Collection<int,IntegrationResource> */
    private function selectedAdAccounts(BrandIntegration $integration)
    {
        return $integration->resources()
            ->ofType(IntegrationResource::TYPE_AD_ACCOUNT)
            ->selected()
            ->get();
    }

    private function upsertAdAccount(BrandIntegration $integration, IntegrationResource $resource): PlatformAdAccount
    {
        return PlatformAdAccount::updateOrCreate(
            ['platform' => self::PLATFORM, 'external_id' => $resource->external_id],
            [
                'brand_id'             => $integration->brand_id,
                'brand_integration_id' => $integration->id,
                'name'                 => $resource->name,
                'currency'             => $resource->metadata['currency'] ?? null,
                'timezone'             => $resource->metadata['timezone'] ?? null,
                'status'               => $resource->status,
                'metadata'             => $resource->metadata,
            ]
        );
    }

    /** @return array<int,PlatformCampaign> */
    private function syncCampaigns(MetaApiService $api, PlatformAdAccount $account): array
    {
        $rows = $api->paginate($account->external_id . '/campaigns', [
            'fields' => 'id,name,objective,status,buying_type,daily_budget,lifetime_budget,start_time,stop_time',
        ]);

        return array_map(fn (array $row) => PlatformCampaign::updateOrCreate(
            ['platform' => self::PLATFORM, 'external_id' => $row['id']],
            [
                'brand_id'               => $account->brand_id,
                'platform_ad_account_id' => $account->id,
                'name'                   => $row['name'] ?? $row['id'],
                'objective'              => $row['objective'] ?? null,
                'status'                 => $row['status'] ?? null,
                'buying_type'            => $row['buying_type'] ?? null,
                // Meta returns money in minor units (cents/paisa) as a string.
                'daily_budget'           => $this->money($row['daily_budget'] ?? null),
                'lifetime_budget'        => $this->money($row['lifetime_budget'] ?? null),
                'started_at'             => $this->time($row['start_time'] ?? null),
                'stopped_at'             => $this->time($row['stop_time'] ?? null),
                'metadata'               => $row,
                'last_synced_at'         => now(),
            ]
        ), $rows);
    }

    /** @return array<int,PlatformAdSet> */
    private function syncAdSets(MetaApiService $api, PlatformCampaign $campaign): array
    {
        $rows = $api->paginate($campaign->external_id . '/adsets', [
            'fields' => 'id,name,status,optimization_goal,billing_event,daily_budget,lifetime_budget,start_time,end_time,targeting',
        ]);

        return array_map(fn (array $row) => PlatformAdSet::updateOrCreate(
            ['platform' => self::PLATFORM, 'external_id' => $row['id']],
            [
                'brand_id'             => $campaign->brand_id,
                'platform_campaign_id' => $campaign->id,
                'name'                 => $row['name'] ?? $row['id'],
                'status'               => $row['status'] ?? null,
                'optimization_goal'    => $row['optimization_goal'] ?? null,
                'billing_event'        => $row['billing_event'] ?? null,
                'daily_budget'         => $this->money($row['daily_budget'] ?? null),
                'lifetime_budget'      => $this->money($row['lifetime_budget'] ?? null),
                'starts_at'            => $this->time($row['start_time'] ?? null),
                'ends_at'              => $this->time($row['end_time'] ?? null),
                'targeting'            => $row['targeting'] ?? null,
                'metadata'             => $row,
                'last_synced_at'       => now(),
            ]
        ), $rows);
    }

    /** @return array<int,PlatformAd> */
    private function syncAds(MetaApiService $api, PlatformAdSet $adSet): array
    {
        $rows = $api->paginate($adSet->external_id . '/ads', [
            'fields' => 'id,name,status,creative{id,body,title,link_url,call_to_action_type,thumbnail_url},preview_shareable_link',
        ]);

        return array_map(function (array $row) use ($adSet) {
            $creative = $row['creative'] ?? [];

            return PlatformAd::updateOrCreate(
                ['platform' => self::PLATFORM, 'external_id' => $row['id']],
                [
                    'brand_id'             => $adSet->brand_id,
                    'platform_ad_set_id'   => $adSet->id,
                    'name'                 => $row['name'] ?? $row['id'],
                    'status'               => $row['status'] ?? null,
                    'creative_external_id' => $creative['id'] ?? null,
                    'primary_text'         => $creative['body'] ?? null,
                    'headline'             => $creative['title'] ?? null,
                    'call_to_action'       => $creative['call_to_action_type'] ?? null,
                    'destination_url'      => $creative['link_url'] ?? null,
                    'thumbnail_url'        => $creative['thumbnail_url'] ?? null,
                    'preview_url'          => $row['preview_shareable_link'] ?? null,
                    'metadata'             => $row,
                    'last_synced_at'       => now(),
                ]
            );
        }, $rows);
    }

    /**
     * Daily campaign-level insights for the trailing window.
     *
     * Campaign level is the granularity the dashboard reports on; ad-level rows
     * would multiply the row count by an order of magnitude for numbers nothing
     * currently displays.
     */
    private function syncInsights(MetaApiService $api, PlatformAdAccount $account): int
    {
        $since = now()->subDays(self::INSIGHT_WINDOW_DAYS)->toDateString();
        $until = now()->toDateString();

        $rows = $api->paginate($account->external_id . '/insights', [
            'level'       => 'campaign',
            'time_range'  => json_encode(['since' => $since, 'until' => $until]),
            'time_increment' => 1,          // one row per day, not one total
            'fields'      => 'campaign_id,spend,impressions,reach,clicks,ctr,cpc,cpm,actions,action_values,date_start',
        ]);

        $campaignIds = PlatformCampaign::where('platform_ad_account_id', $account->id)
            ->pluck('id', 'external_id');

        $count = 0;

        foreach ($rows as $row) {
            $externalId = $row['campaign_id'] ?? null;

            if (!$externalId || !isset($row['date_start'])) {
                continue;
            }

            [$conversions, $conversionValue] = $this->conversions($row);

            PlatformAdInsight::updateOrCreate(
                [
                    'platform'    => self::PLATFORM,
                    'level'       => PlatformAdInsight::LEVEL_CAMPAIGN,
                    'external_id' => $externalId,
                    'date'        => $row['date_start'],
                ],
                [
                    'brand_id'               => $account->brand_id,
                    'platform_ad_account_id' => $account->id,
                    'platform_campaign_id'   => $campaignIds[$externalId] ?? null,
                    'spend'                  => (float) ($row['spend'] ?? 0),
                    'impressions'            => (int) ($row['impressions'] ?? 0),
                    'reach'                  => (int) ($row['reach'] ?? 0),
                    'clicks'                 => (int) ($row['clicks'] ?? 0),
                    'ctr'                    => isset($row['ctr']) ? (float) $row['ctr'] : null,
                    'cpc'                    => isset($row['cpc']) ? (float) $row['cpc'] : null,
                    'cpm'                    => isset($row['cpm']) ? (float) $row['cpm'] : null,
                    'conversions'            => $conversions,
                    'conversion_value'       => $conversionValue,
                    'actions'                => $row['actions'] ?? null,
                    'currency'               => $account->currency,
                    'metadata'               => ['action_values' => $row['action_values'] ?? null],
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Pull purchase counts and value out of Meta's action arrays.
     *
     * Meta reports every action type in one list; only purchases are treated as
     * conversions here, so ROAS means what a shop owner expects it to mean.
     *
     * @return array{0:int,1:float}
     */
    private function conversions(array $row): array
    {
        $isPurchase = fn (array $action) => in_array(
            $action['action_type'] ?? '',
            ['purchase', 'offsite_conversion.fb_pixel_purchase', 'omni_purchase'],
            true
        );

        $count = 0;
        foreach ($row['actions'] ?? [] as $action) {
            if ($isPurchase($action)) {
                $count += (int) ($action['value'] ?? 0);
            }
        }

        $value = 0.0;
        foreach ($row['action_values'] ?? [] as $action) {
            if ($isPurchase($action)) {
                $value += (float) ($action['value'] ?? 0);
            }
        }

        return [$count, $value];
    }

    /** Meta sends money as minor units in a string. */
    private function money(mixed $raw): ?float
    {
        return $raw === null || $raw === '' ? null : ((float) $raw) / 100;
    }

    private function time(mixed $raw): ?Carbon
    {
        return $raw ? Carbon::parse($raw) : null;
    }
}
