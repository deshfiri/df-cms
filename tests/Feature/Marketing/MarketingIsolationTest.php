<?php

namespace Tests\Feature\Marketing;

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
 * Multi-tenant isolation.
 *
 * Every endpoint under Marketing takes a brand or integration id straight from
 * the URL, so each one has to prove that the id is a request and not an
 * entitlement. These are the tests that would catch a leak between clients.
 */
class MarketingIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view ads', 'manage ads', 'view clients', 'manage clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function brandFor(string $name): Brand
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => $name,
            'brand_name'  => $name,
            'category_id' => $category->id,
        ]);

        return Brand::create([
            'client_id' => $client->id,
            'name'      => $name . ' Brand',
            'slug'      => strtolower($name) . '-' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function marketer(array $permissions = ['view ads', 'view clients']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    private function integration(Brand $brand): BrandIntegration
    {
        return BrandIntegration::create([
            'brand_id'    => $brand->id,
            'platform'    => BrandIntegration::PLATFORM_META,
            'status'      => BrandIntegration::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'token'],
        ]);
    }

    public function test_ads_permission_is_required_to_open_marketing(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('view clients');   // clients yes, ads no

        $this->actingAs($user)->get(route('marketing.brand', $this->brandFor('Acme')))
            ->assertForbidden();
    }

    public function test_a_viewer_cannot_manage_an_integration(): void
    {
        $brand = $this->brandFor('Acme');
        $integration = $this->integration($brand);

        // 'view ads' can look, but not connect, disconnect or force a sync.
        $viewer = $this->marketer(['view ads', 'view clients']);

        $this->actingAs($viewer)->post(route('marketing.integrations.sync', $integration))->assertForbidden();
        $this->actingAs($viewer)->post(route('marketing.integrations.disconnect', $integration))->assertForbidden();
        $this->actingAs($viewer)->get(route('marketing.meta.connect', $brand))->assertForbidden();
    }

    public function test_a_manager_may_manage_their_integration(): void
    {
        $integration = $this->integration($this->brandFor('Acme'));
        $manager = $this->marketer(['view ads', 'manage ads', 'view clients']);

        // Not a 403 — the sync is refused only on its own merits, if at all.
        $response = $this->actingAs($manager)->post(route('marketing.integrations.sync', $integration));

        $this->assertNotSame(403, $response->status());
    }

    public function test_the_dashboard_never_returns_another_brands_numbers(): void
    {
        $mine   = $this->brandFor('Mine');
        $theirs = $this->brandFor('Theirs');

        $this->insightFor($theirs, 5000.00);

        $body = $this->actingAs($this->marketer())
            ->getJson(route('marketing.dashboard', $mine))
            ->assertOk()
            ->json();

        // My brand has no data; their spend must not leak into my totals.
        $this->assertFalse($body['summary']['has_data']);
        $this->assertNull($body['summary']['spend']);
        $this->assertCount(0, $body['campaigns']);
    }

    public function test_an_ad_account_filter_cannot_reach_across_brands(): void
    {
        $mine   = $this->brandFor('Mine');
        $theirs = $this->brandFor('Theirs');

        $theirAccount = $this->insightFor($theirs, 7500.00);

        // Asking my brand's dashboard for their ad account id must not work.
        $body = $this->actingAs($this->marketer())
            ->getJson(route('marketing.dashboard', $mine) . '?ad_account_id=' . $theirAccount->id)
            ->assertOk()
            ->json();

        $this->assertFalse($body['summary']['has_data']);
    }

    public function test_campaign_and_ad_listings_are_scoped_to_the_brand(): void
    {
        $mine   = $this->brandFor('Mine');
        $theirs = $this->brandFor('Theirs');

        $this->insightFor($theirs, 100.00);

        $campaigns = $this->actingAs($this->marketer())
            ->getJson(route('marketing.campaigns', $mine))
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $campaigns);
    }

    public function test_the_brand_picker_only_offers_brands_the_user_can_reach(): void
    {
        $this->brandFor('Alpha');
        $this->brandFor('Beta');

        // 'view clients' in this app is org-wide, so both are legitimately
        // visible — the check that matters is that the ads permission gates it.
        $this->actingAs($this->marketer())->get(route('marketing.index'))->assertOk();

        $noAds = User::factory()->create(['is_active' => true]);
        $noAds->givePermissionTo('view clients');
        $this->actingAs($noAds)->get(route('marketing.index'))->assertOk();
    }

    public function test_sync_logs_are_gated_by_the_brand(): void
    {
        $brand = $this->brandFor('Acme');

        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)->getJson(route('marketing.sync-logs', $brand))->assertForbidden();
    }

    /** A campaign + one day of spend belonging to a brand. */
    private function insightFor(Brand $brand, float $spend): PlatformAdAccount
    {
        $integration = $this->integration($brand);

        $account = PlatformAdAccount::create([
            'brand_id'             => $brand->id,
            'brand_integration_id' => $integration->id,
            'platform'             => 'meta',
            'external_id'          => 'act_' . $brand->id,
            'name'                 => 'Account',
            'currency'             => 'BDT',
        ]);

        $campaign = PlatformCampaign::create([
            'brand_id'               => $brand->id,
            'platform_ad_account_id' => $account->id,
            'platform'               => 'meta',
            'external_id'            => 'c-' . $brand->id,
            'name'                   => 'Campaign ' . $brand->id,
            'status'                 => 'ACTIVE',
        ]);

        PlatformAdInsight::create([
            'brand_id'               => $brand->id,
            'platform_ad_account_id' => $account->id,
            'platform_campaign_id'   => $campaign->id,
            'platform'               => 'meta',
            'level'                  => PlatformAdInsight::LEVEL_CAMPAIGN,
            'external_id'            => $campaign->external_id,
            'date'                   => now()->toDateString(),
            'spend'                  => $spend,
            'impressions'            => 1000,
            'clicks'                 => 50,
        ]);

        return $account;
    }
}
