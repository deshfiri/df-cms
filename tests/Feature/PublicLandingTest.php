<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — the public, unauthenticated landing page. Strict data boundary:
 * only is_public + is_active Brands/Products, never Client/Invoice/Payment/
 * AdvertisingExpenditure/BrandChecklist/ContentItem/ActivityLog data.
 */
class PublicLandingTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Secret Client Name', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    private function brand(array $attrs = []): Brand
    {
        $client = $this->client();

        return Brand::create(array_merge([
            'client_id' => $client->id, 'name' => 'Brand ' . uniqid(),
            'is_active' => true, 'is_public' => true,
        ], $attrs));
    }

    public function test_guest_sees_only_public_active_brands(): void
    {
        $visible = $this->brand(['name' => 'Visible Brand']);
        $this->brand(['name' => 'Not Public Brand', 'is_public' => false]);
        $this->brand(['name' => 'Inactive Brand', 'is_public' => true, 'is_active' => false]);

        $response = $this->get(route('landing'));

        $response->assertOk();
        $response->assertSee('Visible Brand');
        $response->assertDontSee('Not Public Brand');
        $response->assertDontSee('Inactive Brand');
    }

    public function test_direct_access_to_a_non_public_brand_returns_404(): void
    {
        $brand = $this->brand(['is_public' => false]);

        $response = $this->get(route('landing.brand', $brand));

        $response->assertNotFound();
    }

    public function test_direct_access_to_an_inactive_brand_returns_404(): void
    {
        $brand = $this->brand(['is_public' => true, 'is_active' => false]);

        $response = $this->get(route('landing.brand', $brand));

        $response->assertNotFound();
    }

    public function test_brand_detail_only_shows_public_and_active_products(): void
    {
        $brand = $this->brand();
        Product::create(['brand_id' => $brand->id, 'name' => 'Visible Product', 'is_active' => true, 'is_public' => true]);
        Product::create(['brand_id' => $brand->id, 'name' => 'Not Public Product', 'is_active' => true, 'is_public' => false]);
        Product::create(['brand_id' => $brand->id, 'name' => 'Inactive Product', 'is_active' => false, 'is_public' => true]);

        $response = $this->get(route('landing.brand', $brand));

        $response->assertOk();
        $response->assertSee('Visible Product');
        $response->assertDontSee('Not Public Product');
        $response->assertDontSee('Inactive Product');
    }

    public function test_a_product_on_a_non_public_brand_is_never_reachable(): void
    {
        $brand = $this->brand(['is_public' => false]);
        Product::create(['brand_id' => $brand->id, 'name' => 'Orphaned Product', 'is_active' => true, 'is_public' => true]);

        $response = $this->get(route('landing.brand', $brand));

        $response->assertNotFound();
    }

    public function test_an_authenticated_user_is_redirected_away_from_the_landing_page(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->get(route('landing'));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_public_pages_expose_no_internal_financial_or_checklist_data(): void
    {
        $brand = $this->brand(['description' => 'A fine public description.']);
        $checklist = BrandChecklist::create(['brand_id' => $brand->id, 'on_hold_at' => now(), 'on_hold_reason' => 'Secret internal hold reason']);

        $landing = $this->get(route('landing'));
        $detail = $this->get(route('landing.brand', $brand));

        $landing->assertDontSee('Secret Client Name');
        $landing->assertDontSee('Secret internal hold reason');
        $detail->assertDontSee('Secret Client Name');
        $detail->assertDontSee('Secret internal hold reason');
    }
}
