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

    /**
     * Phase 5 — broadens the Phase 4 leak-check beyond client name/hold
     * reason: a brand with real money, content, and audit trail behind it
     * must still render a clean page with none of it.
     */
    public function test_public_pages_expose_no_financial_content_workflow_or_audit_data(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $manager->assignRole('Manager');

        $brand = $this->brand(['description' => 'A fine public description.']);
        \App\Models\PaymentCategory::firstOrCreate(['name' => 'Social Media Ads'], ['is_active' => true, 'sort_order' => 10]);
        $invoice = \App\Models\Invoice::create([
            'client_id' => $brand->client_id, 'brand_id' => $brand->id,
            'payment_category_id' => \App\Models\PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-SECRETNUM', 'total_payable' => 123456.78, 'status' => \App\Models\Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        \App\Models\Payment::create([
            'client_id' => $brand->client_id, 'invoice_id' => $invoice->id, 'payment_category_id' => $invoice->payment_category_id,
            'amount' => 123456.78, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $manager->id,
        ]);
        \App\Models\AdvertisingExpenditure::create([
            'brand_id' => $brand->id, 'amount' => 98765.43, 'reporting_date' => now()->toDateString(),
            'note' => 'Internal spend note nobody public should see', 'recorded_by' => $manager->id,
        ]);
        $checklist = \App\Models\BrandChecklist::create(['brand_id' => $brand->id]);
        \App\Models\ContentItem::create([
            'checklist_id' => $checklist->id, 'brand_id' => $brand->id, 'category' => 'raw_content',
            'status' => 'pending', 'title' => 'Confidential Content Item Title', 'created_by' => $manager->id,
        ]);
        app(\App\Services\ActivityLogService::class)->log('Advertising Expenditure', 'Recorded', $brand->client_id, null, [
            'note' => 'Audit trail entry nobody public should see',
        ]);

        $landing = $this->get(route('landing'));
        $detail = $this->get(route('landing.brand', $brand));

        foreach ([$landing, $detail] as $response) {
            $response->assertDontSee('INV-SECRETNUM');
            $response->assertDontSee('123456.78');
            $response->assertDontSee('98765.43');
            $response->assertDontSee('Internal spend note nobody public should see');
            $response->assertDontSee('Confidential Content Item Title');
            $response->assertDontSee('Audit trail entry nobody public should see');
        }
    }
}
