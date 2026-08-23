<?php

namespace Tests\Feature\Marketing;

use App\Models\Brand;
use App\Models\BrandIntegration;
use App\Models\Category;
use App\Models\Client;
use App\Models\PlatformAd;
use App\Models\PlatformAdAccount;
use App\Models\PlatformAdSet;
use App\Models\PlatformCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Browsing the synced hierarchy: campaigns → ad sets → ads.
 *
 * The drill-down passes ids in the query string, so each level has to prove it
 * still scopes to the brand rather than trusting the id it was handed.
 */
class MarketingBrowseTest extends TestCase
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

    private function brand(string $name): Brand
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
            'name'      => $name,
            'slug'      => strtolower($name) . '-' . uniqid(),
            'is_active' => true,
        ]);
    }

    /** @return array{0:PlatformCampaign,1:PlatformAdSet,2:PlatformAd} */
    private function hierarchyFor(Brand $brand): array
    {
        $integration = BrandIntegration::create([
            'brand_id' => $brand->id,
            'platform' => 'meta',
            'status'   => BrandIntegration::STATUS_CONNECTED,
        ]);

        $account = PlatformAdAccount::create([
            'brand_id'             => $brand->id,
            'brand_integration_id' => $integration->id,
            'platform'             => 'meta',
            'external_id'          => 'act_' . $brand->id,
            'name'                 => 'Account ' . $brand->id,
            'currency'             => 'BDT',
        ]);

        $campaign = PlatformCampaign::create([
            'brand_id'               => $brand->id,
            'platform_ad_account_id' => $account->id,
            'platform'               => 'meta',
            'external_id'            => 'c-' . $brand->id,
            'name'                   => $brand->name . ' Campaign',
            'status'                 => 'ACTIVE',
        ]);

        $adSet = PlatformAdSet::create([
            'brand_id'             => $brand->id,
            'platform_campaign_id' => $campaign->id,
            'platform'             => 'meta',
            'external_id'          => 'as-' . $brand->id,
            'name'                 => $brand->name . ' Ad Set',
            'status'               => 'ACTIVE',
        ]);

        $ad = PlatformAd::create([
            'brand_id'           => $brand->id,
            'platform_ad_set_id' => $adSet->id,
            'platform'           => 'meta',
            'external_id'        => 'ad-' . $brand->id,
            'name'               => $brand->name . ' Ad',
            'status'             => 'ACTIVE',
            'headline'           => 'Buy now',
        ]);

        return [$campaign, $adSet, $ad];
    }

    public function test_the_browse_page_renders(): void
    {
        $brand = $this->brand('Acme');

        $this->actingAs($this->marketer())
            ->get(route('marketing.browse', $brand))
            ->assertOk()
            ->assertSee('Campaigns', false)
            ->assertSee('Ad Sets', false);
    }

    public function test_each_level_lists_only_this_brands_rows(): void
    {
        $mine   = $this->brand('Mine');
        $theirs = $this->brand('Theirs');

        $this->hierarchyFor($mine);
        $this->hierarchyFor($theirs);

        $user = $this->marketer();

        foreach (['campaigns', 'ad-sets', 'ads'] as $level) {
            $rows = $this->actingAs($user)
                ->getJson(route('marketing.' . $level, $mine))
                ->assertOk()
                ->json('data');

            $this->assertCount(1, $rows, "{$level} leaked another brand's rows");
            $this->assertStringStartsWith('Mine', $rows[0]['name']);
        }
    }

    public function test_drilling_into_a_campaign_filters_its_ad_sets(): void
    {
        $brand = $this->brand('Acme');
        [$campaign] = $this->hierarchyFor($brand);

        $rows = $this->actingAs($this->marketer())
            ->getJson(route('marketing.ad-sets', $brand) . '?campaign_id=' . $campaign->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($campaign->id, $rows[0]['platform_campaign_id']);
    }

    public function test_a_campaign_id_from_another_brand_matches_nothing(): void
    {
        $mine   = $this->brand('Mine');
        $theirs = $this->brand('Theirs');

        $this->hierarchyFor($mine);
        [$theirCampaign] = $this->hierarchyFor($theirs);

        // Handing my brand's endpoint their campaign id must return nothing,
        // not their ad sets.
        $rows = $this->actingAs($this->marketer())
            ->getJson(route('marketing.ad-sets', $mine) . '?campaign_id=' . $theirCampaign->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $rows);
    }

    public function test_an_ad_set_id_from_another_brand_matches_nothing(): void
    {
        $mine   = $this->brand('Mine');
        $theirs = $this->brand('Theirs');

        $this->hierarchyFor($mine);
        [, $theirAdSet] = $this->hierarchyFor($theirs);

        $rows = $this->actingAs($this->marketer())
            ->getJson(route('marketing.ads', $mine) . '?ad_set_id=' . $theirAdSet->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $rows);
    }

    public function test_the_listings_carry_their_parent_names_for_display(): void
    {
        $brand = $this->brand('Acme');
        $this->hierarchyFor($brand);

        $user = $this->marketer();

        $adSet = $this->actingAs($user)->getJson(route('marketing.ad-sets', $brand))->json('data.0');
        $ad    = $this->actingAs($user)->getJson(route('marketing.ads', $brand))->json('data.0');

        // The table shows the parent's name, so the relation must be loaded.
        $this->assertSame('Acme Campaign', $adSet['campaign']['name']);
        $this->assertSame('Acme Ad Set', $ad['ad_set']['name']);
    }

    public function test_browsing_requires_the_ads_permission(): void
    {
        $brand = $this->brand('Acme');

        $stranger = User::factory()->create(['is_active' => true]);
        $stranger->givePermissionTo('view clients');

        $this->actingAs($stranger)->get(route('marketing.browse', $brand))->assertForbidden();
        $this->actingAs($stranger)->getJson(route('marketing.campaigns', $brand))->assertForbidden();
    }
}
