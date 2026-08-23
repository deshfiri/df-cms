<?php

namespace Tests\Feature\Marketing;

use App\Jobs\SyncBrandIntegration;
use App\Models\Brand;
use App\Models\BrandIntegration;
use App\Models\Category;
use App\Models\Client;
use App\Models\IntegrationResource;
use App\Models\PlatformAd;
use App\Models\PlatformAdInsight;
use App\Models\PlatformAdSet;
use App\Models\PlatformCampaign;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\PlatformSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The sync pipeline against a faked Graph API.
 *
 * These cover the parts that would quietly corrupt data if wrong: upserting on
 * external ids, isolating one brand's failure from another's, and never letting
 * a token reach a response.
 */
class MetaSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view ads', 'manage ads', 'view clients', 'manage clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        config([
            'services.meta.app_id'      => 'test-app',
            'services.meta.app_secret'  => 'test-secret',
            'services.meta.api_version' => 'v21.0',
        ]);
    }

    private function brand(string $name = 'Karima'): Brand
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => $name . ' Ltd',
            'brand_name'  => $name,
            'category_id' => $category->id,
        ]);

        return Brand::create([
            'client_id' => $client->id,
            'name'      => $name,
            'slug'      => strtolower($name) . '-' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function connectedIntegration(Brand $brand, string $adAccountId = 'act_100'): BrandIntegration
    {
        $integration = BrandIntegration::create([
            'brand_id'     => $brand->id,
            'platform'     => BrandIntegration::PLATFORM_META,
            'status'       => BrandIntegration::STATUS_CONNECTED,
            'credentials'  => ['access_token' => 'secret-token-value'],
            'metadata'     => ['account_name' => 'Test Business'],
            'connected_at' => now(),
        ]);

        IntegrationResource::create([
            'brand_integration_id' => $integration->id,
            'type'                 => IntegrationResource::TYPE_AD_ACCOUNT,
            'external_id'          => $adAccountId,
            'name'                 => 'Test Ad Account',
            'status'               => 'active',
            'metadata'             => ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka'],
            'is_selected'          => true,
        ]);

        return $integration->fresh();
    }

    /** A Graph API that returns one campaign → ad set → ad → a day of insights. */
    private function fakeMetaApi(string $adAccountId = 'act_100', string $campaignId = 'c-1'): void
    {
        Http::fake([
            '*/campaigns*' => Http::response(['data' => [[
                'id' => $campaignId, 'name' => 'Ramadan Push', 'objective' => 'OUTCOME_SALES',
                'status' => 'ACTIVE', 'daily_budget' => '150000',   // minor units
            ]]]),
            '*/adsets*' => Http::response(['data' => [[
                'id' => 'as-1', 'name' => 'Dhaka 18-34', 'status' => 'ACTIVE',
                'optimization_goal' => 'OFFSITE_CONVERSIONS', 'daily_budget' => '50000',
            ]]]),
            '*/ads*' => Http::response(['data' => [[
                'id' => 'ad-1', 'name' => 'Carousel A', 'status' => 'ACTIVE',
                'creative' => ['id' => 'cr-1', 'body' => 'Shop now', 'title' => 'Eid Sale',
                               'call_to_action_type' => 'SHOP_NOW', 'link_url' => 'https://example.com'],
            ]]]),
            '*/insights*' => Http::response(['data' => [[
                'campaign_id' => $campaignId, 'date_start' => now()->toDateString(),
                'spend' => '1200.50', 'impressions' => '45000', 'reach' => '31000', 'clicks' => '900',
                'ctr' => '2.0', 'cpc' => '1.33', 'cpm' => '26.67',
                'actions'       => [['action_type' => 'purchase', 'value' => '25']],
                'action_values' => [['action_type' => 'purchase', 'value' => '6000']],
            ]]]),
        ]);
    }

    public function test_a_sync_pulls_the_whole_hierarchy(): void
    {
        $brand = $this->brand();
        $integration = $this->connectedIntegration($brand);
        $this->fakeMetaApi();

        $log = app(PlatformSyncService::class)->syncIntegration($integration);

        $this->assertSame(SyncLog::STATUS_SUCCESS, $log->status);
        $this->assertDatabaseCount('platform_ad_accounts', 1);
        $this->assertDatabaseCount('platform_campaigns', 1);
        $this->assertDatabaseCount('platform_ad_sets', 1);
        $this->assertDatabaseCount('platform_ads', 1);
        $this->assertDatabaseCount('platform_ad_insights', 1);

        // Money arrives in minor units and must be stored as currency.
        $this->assertEquals(1500.00, PlatformCampaign::first()->daily_budget);

        $insight = PlatformAdInsight::first();
        $this->assertEquals(1200.50, $insight->spend);
        $this->assertSame(25, $insight->conversions);
        $this->assertEquals(6000, $insight->conversion_value);
        $this->assertSame($brand->id, $insight->brand_id);
    }

    public function test_syncing_twice_updates_rather_than_duplicates(): void
    {
        $brand = $this->brand();
        $integration = $this->connectedIntegration($brand);
        $this->fakeMetaApi();

        app(PlatformSyncService::class)->syncIntegration($integration);
        app(PlatformSyncService::class)->syncIntegration($integration->fresh());

        // Every level is keyed on the platform's own id.
        $this->assertDatabaseCount('platform_campaigns', 1);
        $this->assertDatabaseCount('platform_ad_sets', 1);
        $this->assertDatabaseCount('platform_ads', 1);
        $this->assertDatabaseCount('platform_ad_insights', 1);
    }

    public function test_a_changed_campaign_name_is_updated_in_place(): void
    {
        $brand = $this->brand();
        $integration = $this->connectedIntegration($brand);

        // One stateful fake rather than two calls: Http::fake() *appends*
        // stubs, so a second call would leave the first still matching first.
        $renamed = false;
        Http::fake(function ($request) use (&$renamed) {
            if (str_contains($request->url(), '/campaigns')) {
                return Http::response(['data' => [[
                    'id'     => 'c-1',
                    'name'   => $renamed ? 'Ramadan Push (renamed)' : 'Ramadan Push',
                    'status' => $renamed ? 'PAUSED' : 'ACTIVE',
                ]]]);
            }

            return Http::response(['data' => []]);
        });

        app(PlatformSyncService::class)->syncIntegration($integration);

        $renamed = true;
        app(PlatformSyncService::class)->syncIntegration($integration->fresh());

        $campaign = PlatformCampaign::first();
        $this->assertSame('Ramadan Push (renamed)', $campaign->name);
        $this->assertSame('PAUSED', $campaign->status);
        $this->assertDatabaseCount('platform_campaigns', 1);
    }

    public function test_an_expired_token_marks_the_integration_and_does_not_throw(): void
    {
        $brand = $this->brand();
        $integration = $this->connectedIntegration($brand);

        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Session has expired', 'code' => 190],
        ], 401)]);

        $log = app(PlatformSyncService::class)->syncIntegration($integration);

        $this->assertSame(SyncLog::STATUS_FAILED, $log->status);
        $this->assertSame(BrandIntegration::STATUS_TOKEN_EXPIRED, $integration->fresh()->status);
        // The user-facing message must be the friendly one, not Meta's raw text.
        $this->assertStringContainsString('Reconnect', $log->error_message);
    }

    public function test_one_brands_failure_does_not_stop_another(): void
    {
        $good = $this->connectedIntegration($this->brand('Good'), 'act_good');
        $bad  = $this->connectedIntegration($this->brand('Bad'), 'act_bad');

        // The bad account 404s; everything else behaves.
        Http::fake([
            '*act_bad*'   => Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 803]], 404),
            '*/campaigns*' => Http::response(['data' => [['id' => 'c-good', 'name' => 'Good Campaign', 'status' => 'ACTIVE']]]),
            '*/adsets*'   => Http::response(['data' => []]),
            '*/insights*' => Http::response(['data' => []]),
        ]);

        $sync = app(PlatformSyncService::class);
        $badLog  = $sync->syncIntegration($bad);
        $goodLog = $sync->syncIntegration($good);

        $this->assertSame(SyncLog::STATUS_FAILED, $badLog->status);
        $this->assertSame(SyncLog::STATUS_SUCCESS, $goodLog->status);
        $this->assertSame('Good Campaign', PlatformCampaign::first()->name);
    }

    public function test_a_disconnected_integration_is_skipped_not_failed(): void
    {
        $integration = $this->connectedIntegration($this->brand());
        $integration->update(['status' => BrandIntegration::STATUS_DISCONNECTED]);

        $log = app(PlatformSyncService::class)->syncIntegration($integration->fresh());

        $this->assertSame(SyncLog::STATUS_SKIPPED, $log->status);
    }

    public function test_only_selected_ad_accounts_are_synced(): void
    {
        $brand = $this->brand();
        $integration = $this->connectedIntegration($brand);

        // A second account the user did not pick.
        IntegrationResource::create([
            'brand_integration_id' => $integration->id,
            'type'                 => IntegrationResource::TYPE_AD_ACCOUNT,
            'external_id'          => 'act_ignored',
            'name'                 => 'Not chosen',
            'is_selected'          => false,
        ]);

        $this->fakeMetaApi();
        app(PlatformSyncService::class)->syncIntegration($integration);

        $this->assertDatabaseCount('platform_ad_accounts', 1);
        $this->assertDatabaseMissing('platform_ad_accounts', ['external_id' => 'act_ignored']);
    }

    public function test_the_scheduler_queues_a_job_per_connected_integration(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->connectedIntegration($this->brand('One'), 'act_1');
        $this->connectedIntegration($this->brand('Two'), 'act_2');

        // Deactivated brands cost nothing.
        $sleeping = $this->connectedIntegration($this->brand('Three'), 'act_3');
        $sleeping->brand->update(['is_active' => false]);

        $this->artisan('platforms:sync')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(SyncBrandIntegration::class, 2);
    }

    public function test_the_access_token_never_appears_in_a_serialised_integration(): void
    {
        $integration = $this->connectedIntegration($this->brand());

        $json = json_encode($integration->toArray());

        $this->assertStringNotContainsString('secret-token-value', $json);
        $this->assertStringNotContainsString('credentials', $json);
        // Still readable in code, where it is needed.
        $this->assertSame('secret-token-value', $integration->accessToken());
    }
}
