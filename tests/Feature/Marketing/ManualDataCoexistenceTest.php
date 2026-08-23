<?php

namespace Tests\Feature\Marketing;

use App\Models\AdCampaign;
use App\Models\AdCampaignDailyReport;
use App\Models\Brand;
use App\Models\BrandIntegration;
use App\Models\Category;
use App\Models\Client;
use App\Models\PlatformAdAccount;
use App\Models\PlatformAdInsight;
use App\Models\PlatformCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The manual ads module and the synced platform data coexist.
 *
 * The brief was explicit: preserve the hand-entered system, and keep the two
 * sources distinguishable. These prove a sync never touches manual rows, and
 * that the two sets of figures are reported side by side rather than summed.
 */
class ManualDataCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view ads', 'manage ads', 'view clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function marketer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view ads', 'view clients']);

        return $user->fresh();
    }

    /** @return array{0:Brand,1:Client} */
    private function brand(): array
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);

        $brand = Brand::create([
            'client_id' => $client->id,
            'name'      => 'ACME',
            'slug'      => 'acme-' . uniqid(),
            'is_active' => true,
        ]);

        return [$brand, $client];
    }

    private function manualCampaign(Brand $brand, Client $client, float $spend, float $sales): AdCampaign
    {
        $campaign = AdCampaign::create([
            'client_id' => $client->id,
            'brand_id'  => $brand->id,
            'name'      => 'Hand-entered campaign',
            'platform'  => 'Facebook',
            'status'    => 'Active',
            'budget'    => 10000,
        ]);

        AdCampaignDailyReport::create([
            'ad_campaign_id' => $campaign->id,
            'report_date'    => now()->toDateString(),
            'spend'          => $spend,
            'sales'          => $sales,
            'leads'          => 12,
            'orders'         => 4,
        ]);

        return $campaign;
    }

    public function test_manual_figures_are_reported_separately_from_synced_ones(): void
    {
        [$brand, $client] = $this->brand();
        $this->manualCampaign($brand, $client, 500.00, 2000.00);

        $integration = BrandIntegration::create([
            'brand_id' => $brand->id, 'platform' => 'meta',
            'status'   => BrandIntegration::STATUS_CONNECTED,
        ]);
        $account = PlatformAdAccount::create([
            'brand_id' => $brand->id, 'brand_integration_id' => $integration->id,
            'platform' => 'meta', 'external_id' => 'act_1', 'currency' => 'BDT',
        ]);
        $campaign = PlatformCampaign::create([
            'brand_id' => $brand->id, 'platform_ad_account_id' => $account->id,
            'platform' => 'meta', 'external_id' => 'c-1', 'name' => 'Synced', 'status' => 'ACTIVE',
        ]);
        PlatformAdInsight::create([
            'brand_id' => $brand->id, 'platform_ad_account_id' => $account->id,
            'platform_campaign_id' => $campaign->id, 'platform' => 'meta',
            'level' => PlatformAdInsight::LEVEL_CAMPAIGN, 'external_id' => 'c-1',
            'date' => now()->toDateString(), 'spend' => 1200.00, 'impressions' => 5000, 'clicks' => 100,
        ]);

        $body = $this->actingAs($this->marketer())
            ->getJson(route('marketing.dashboard', $brand))
            ->assertOk()
            ->json();

        // assertEquals, not assertSame: a whole float survives JSON as an int.
        // Synced spend stands alone…
        $this->assertEquals(1200, $body['summary']['spend']);
        // …and the manual figures are reported beside it, not added in.
        $this->assertTrue($body['manual']['has_data']);
        $this->assertEquals(500, $body['manual']['spend']);
        $this->assertEquals(2000, $body['manual']['sales']);
    }

    public function test_a_brand_with_only_manual_data_still_shows_it(): void
    {
        [$brand, $client] = $this->brand();
        $this->manualCampaign($brand, $client, 750.00, 1500.00);

        $body = $this->actingAs($this->marketer())
            ->getJson(route('marketing.dashboard', $brand))
            ->assertOk()
            ->json();

        // Nothing synced: the API summary says so honestly rather than zeroes…
        $this->assertFalse($body['summary']['has_data']);
        $this->assertNull($body['summary']['spend']);
        // …but the hand-entered work is not lost from view.
        $this->assertEquals(750, $body['manual']['spend']);
        $this->assertEquals(2, $body['manual']['roas']);
    }

    public function test_manual_rows_survive_a_sync_untouched(): void
    {
        [$brand, $client] = $this->brand();
        $manual = $this->manualCampaign($brand, $client, 300.00, 900.00);

        $integration = BrandIntegration::create([
            'brand_id'    => $brand->id, 'platform' => 'meta',
            'status'      => BrandIntegration::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'token'],
        ]);
        \App\Models\IntegrationResource::create([
            'brand_integration_id' => $integration->id,
            'type' => 'ad_account', 'external_id' => 'act_1', 'is_selected' => true,
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*/campaigns*' => \Illuminate\Support\Facades\Http::response(['data' => [[
                'id' => 'c-9', 'name' => 'From Meta', 'status' => 'ACTIVE',
            ]]]),
            '*' => \Illuminate\Support\Facades\Http::response(['data' => []]),
        ]);

        app(\App\Services\PlatformSyncService::class)->syncIntegration($integration);

        // The manual campaign and its report are exactly as they were.
        $this->assertDatabaseHas('ad_campaigns', ['id' => $manual->id, 'name' => 'Hand-entered campaign']);
        $this->assertDatabaseHas('ad_campaign_daily_reports', ['ad_campaign_id' => $manual->id, 'spend' => 300.00]);
        // And the synced campaign landed in its own table, not over the top.
        $this->assertDatabaseHas('platform_campaigns', ['external_id' => 'c-9']);
        $this->assertSame(1, AdCampaign::count());
    }

    public function test_another_brands_manual_data_does_not_leak(): void
    {
        [$mine] = $this->brand();
        [$theirs, $theirClient] = $this->brand();

        $this->manualCampaign($theirs, $theirClient, 999.00, 0.00);

        $body = $this->actingAs($this->marketer())
            ->getJson(route('marketing.dashboard', $mine))
            ->assertOk()
            ->json();

        $this->assertFalse($body['manual']['has_data']);
    }
}
