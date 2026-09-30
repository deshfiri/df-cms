<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 0 of the Brand Content & Advertising DMS integration: the
 * foundations everything else builds on — Product CRUD, and the "Record
 * Payment" flow's brand-scoping for the advertising-budget/content-charge
 * categories (Fix J of the plan). See C:\Users\DST\.claude\plans\soft-nibbling-quilt.md.
 */
class BrandContentAdvertisingPhase0Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view clients', 'manage clients', 'manage payments', 'manage products', 'view ads', 'manage ads'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        PaymentCategory::firstOrCreate(['name' => 'Social Media Ads'], ['is_active' => true, 'sort_order' => 10]);
        PaymentCategory::firstOrCreate(['name' => 'Content Production'], ['is_active' => true, 'sort_order' => 20]);
        PaymentCategory::firstOrCreate(['name' => 'Website Development'], ['is_active' => true, 'sort_order' => 30]);
    }

    private function client(string $name = 'Test Client'): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => $name, 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    private function user(string $role, array $extraPerms = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        if ($extraPerms) {
            $user->givePermissionTo($extraPerms);
        }

        return $user;
    }

    // ── Product CRUD ─────────────────────────────────────────────────────

    public function test_manage_products_holder_can_create_a_product_under_a_brand(): void
    {
        $marketing = $this->user('Marketing', ['manage products']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);

        $response = $this->actingAs($marketing)->postJson(route('marketing.products.store', $brand), [
            'name' => 'Wireless Earbuds',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('products', ['brand_id' => $brand->id, 'name' => 'Wireless Earbuds', 'is_active' => 1]);
    }

    public function test_user_without_manage_products_cannot_create_a_product(): void
    {
        $sales = $this->user('Sales');
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);

        $response = $this->actingAs($sales)->postJson(route('marketing.products.store', $brand), [
            'name' => 'Unauthorized Product',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('products', ['name' => 'Unauthorized Product']);
    }

    public function test_product_name_unique_per_brand_but_allowed_across_brands(): void
    {
        $marketing = $this->user('Marketing', ['manage products']);
        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B']);

        $this->actingAs($marketing)->postJson(route('marketing.products.store', $brandA), ['name' => 'Shared Name'])->assertOk();

        $duplicate = $this->actingAs($marketing)->postJson(route('marketing.products.store', $brandA), ['name' => 'Shared Name']);
        $duplicate->assertStatus(422);

        $otherBrand = $this->actingAs($marketing)->postJson(route('marketing.products.store', $brandB), ['name' => 'Shared Name']);
        $otherBrand->assertOk();
    }

    public function test_a_product_can_be_deactivated_and_deleted(): void
    {
        $marketing = $this->user('Marketing', ['manage products']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $product = Product::create(['brand_id' => $brand->id, 'name' => 'Retiring Product']);

        $this->actingAs($marketing)->putJson(route('marketing.products.update', [$brand, $product]), [
            'name' => 'Retiring Product', 'is_active' => false,
        ])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => 0]);

        $this->actingAs($marketing)->deleteJson(route('marketing.products.destroy', [$brand, $product]))->assertOk();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    // ── Record Payment brand-scoping (Fix J) ────────────────────────────

    public function test_a_client_with_no_brands_can_still_record_a_social_media_ads_charge_without_a_brand(): void
    {
        // Preserves pre-existing behaviour for clients that never used the
        // Brand Content & Advertising feature at all.
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();

        $response = $this->actingAs($accounts)->postJson(route('clients.payments.store', $client), [
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'charge_total' => 20000, 'charge_title' => 'October ads', 'amount' => 5000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('invoices', ['client_id' => $client->id, 'brand_id' => null]);
    }

    public function test_a_client_with_a_brand_must_pick_one_for_a_social_media_ads_charge(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        Brand::create(['client_id' => $client->id, 'name' => 'Only Brand']);

        $response = $this->actingAs($accounts)->postJson(route('clients.payments.store', $client), [
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'charge_total' => 20000, 'charge_title' => 'October ads', 'amount' => 5000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('brand_id');
        $this->assertDatabaseMissing('invoices', ['client_id' => $client->id]);
    }

    public function test_recording_an_advertising_budget_payment_with_a_brand_links_the_invoice_to_it(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);

        $response = $this->actingAs($accounts)->postJson(route('clients.payments.store', $client), [
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'charge_total' => 20000, 'charge_title' => 'October ads', 'amount' => 5000, 'brand_id' => $brand->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('invoices', ['client_id' => $client->id, 'brand_id' => $brand->id, 'total_payable' => 20000]);
    }

    public function test_a_brand_belonging_to_a_different_client_is_rejected(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        $otherClient = $this->client('Other Client');
        $foreignBrand = Brand::create(['client_id' => $otherClient->id, 'name' => 'Foreign Brand']);

        $response = $this->actingAs($accounts)->postJson(route('clients.payments.store', $client), [
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'charge_total' => 20000, 'charge_title' => 'October ads', 'amount' => 5000, 'brand_id' => $foreignBrand->id,
        ]);

        // The cross-client PaymentService check rejects it after passing basic validation.
        $response->assertStatus(422);
        $this->assertDatabaseMissing('invoices', ['client_id' => $client->id]);
    }

    // ── Content Production charges (InvoiceController::store) ───────────
    // The same brand_id rule as Social Media Ads, but through the standalone
    // charge-only endpoint (no payment) rather than Record Payment's
    // "+ New charge" — this is the actual path a content charge takes.

    public function test_content_production_charge_for_a_single_brand_client_requires_the_brand(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Only Brand']);
        $contentCat = PaymentCategory::where('name', 'Content Production')->value('id');

        $missing = $this->actingAs($accounts)->postJson(route('clients.invoices.store', $client), [
            'payment_category_id' => $contentCat, 'total_payable' => 500, 'title' => 'Content charge',
        ]);
        $missing->assertStatus(422);
        $missing->assertJsonValidationErrors('brand_id');
        $this->assertDatabaseMissing('invoices', ['client_id' => $client->id]);

        $withBrand = $this->actingAs($accounts)->postJson(route('clients.invoices.store', $client), [
            'payment_category_id' => $contentCat, 'total_payable' => 500, 'title' => 'Content charge', 'brand_id' => $brand->id,
        ]);
        $withBrand->assertOk();
        $this->assertDatabaseHas('invoices', ['client_id' => $client->id, 'brand_id' => $brand->id, 'total_payable' => 500]);
    }

    public function test_content_production_charge_for_a_multi_brand_client_requires_picking_one(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B']);
        $contentCat = PaymentCategory::where('name', 'Content Production')->value('id');

        $missing = $this->actingAs($accounts)->postJson(route('clients.invoices.store', $client), [
            'payment_category_id' => $contentCat, 'total_payable' => 500, 'title' => 'Content charge',
        ]);
        $missing->assertStatus(422);
        $missing->assertJsonValidationErrors('brand_id');

        $response = $this->actingAs($accounts)->postJson(route('clients.invoices.store', $client), [
            'payment_category_id' => $contentCat, 'total_payable' => 500, 'title' => 'Content charge', 'brand_id' => $brandB->id,
        ]);
        $response->assertOk();
        // Picked brand B specifically — not just "a" brand, and not brand A.
        $this->assertDatabaseHas('invoices', ['client_id' => $client->id, 'brand_id' => $brandB->id]);
        $this->assertDatabaseMissing('invoices', ['client_id' => $client->id, 'brand_id' => $brandA->id]);
    }

    public function test_content_production_charge_rejects_a_brand_belonging_to_another_client(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        $otherClient = $this->client('Other Client');
        $foreignBrand = Brand::create(['client_id' => $otherClient->id, 'name' => 'Foreign Brand']);
        $contentCat = PaymentCategory::where('name', 'Content Production')->value('id');

        $response = $this->actingAs($accounts)->postJson(route('clients.invoices.store', $client), [
            'payment_category_id' => $contentCat, 'total_payable' => 500, 'title' => 'Content charge', 'brand_id' => $foreignBrand->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('brand_id');
        $this->assertDatabaseMissing('invoices', ['client_id' => $client->id]);
    }

    public function test_an_ordinary_category_never_requires_a_brand_even_with_brands_present(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);

        $response = $this->actingAs($accounts)->postJson(route('clients.payments.store', $client), [
            'payment_category_id' => PaymentCategory::where('name', 'Website Development')->value('id'),
            'charge_total' => 50000, 'charge_title' => 'New site', 'amount' => 10000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('invoices', ['client_id' => $client->id, 'brand_id' => null]);
    }

    // ── Brand::advertisingBudget() ───────────────────────────────────────

    public function test_advertising_budget_sums_paid_amounts_across_every_social_media_ads_invoice_for_the_brand(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $adsCategory = PaymentCategory::where('name', 'Social Media Ads')->value('id');

        // Two separate top-up charges over time, both paid.
        $this->actingAs($accounts)->postJson(route('clients.payments.store', $client), [
            'payment_category_id' => $adsCategory, 'charge_total' => 500, 'amount' => 500, 'brand_id' => $brand->id,
        ])->assertOk();
        $this->actingAs($accounts)->postJson(route('clients.payments.store', $client), [
            'payment_category_id' => $adsCategory, 'charge_total' => 300, 'amount' => 300, 'brand_id' => $brand->id,
        ])->assertOk();

        $this->assertSame(800.0, $brand->fresh()->advertisingBudget());
    }

    public function test_advertising_budget_ignores_other_brands_categories_and_unpaid_invoices(): void
    {
        $accounts = $this->user('Accounts', ['manage payments']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $otherBrand = Brand::create(['client_id' => $client->id, 'name' => 'Brand B']);
        $adsCategory = PaymentCategory::where('name', 'Social Media Ads')->value('id');

        // Paid, for the OTHER brand — must not count toward Brand A's budget.
        $this->actingAs($accounts)->postJson(route('clients.payments.store', $client), [
            'payment_category_id' => $adsCategory, 'charge_total' => 999, 'amount' => 999, 'brand_id' => $otherBrand->id,
        ])->assertOk();

        // A different category entirely for Brand A — must not count either.
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Website Development')->value('id'),
            'invoice_number' => 'INV-TEST-1', 'total_payable' => 1000, 'status' => Invoice::STATUS_PAID,
            'issued_by' => $accounts->id, 'issued_date' => now(),
        ]);

        // An UNPAID Social Media Ads charge for Brand A itself — not part of the budget yet.
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id, 'payment_category_id' => $adsCategory,
            'invoice_number' => 'INV-TEST-2', 'total_payable' => 2000, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $accounts->id, 'issued_date' => now(),
        ]);

        $this->assertSame(0.0, $brand->fresh()->advertisingBudget());
    }

    // ── Seeder ───────────────────────────────────────────────────────────

    public function test_seeder_creates_the_social_media_manager_role_and_new_permissions(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $smm = Role::where('name', 'Social Media Manager')->first();
        $this->assertNotNull($smm);
        $this->assertTrue($smm->hasPermissionTo('manage smm-collection'));
        $this->assertTrue($smm->hasPermissionTo('manage published-content'));

        $content = Role::where('name', 'Content')->first();
        $this->assertTrue($content->hasPermissionTo('manage raw-content'));

        $design = Role::where('name', 'Design')->first();
        $this->assertTrue($design->hasPermissionTo('manage designer-content'));

        $marketing = Role::where('name', 'Marketing')->first();
        $this->assertTrue($marketing->hasPermissionTo('manage content-charges'));
        $this->assertTrue($marketing->hasPermissionTo('manage advertising-expenditure'));
        $this->assertTrue($marketing->hasPermissionTo('manage publishing-review'));

        $manager = Role::where('name', 'Manager')->first();
        $this->assertTrue($manager->hasPermissionTo('view brand-checklist-overview'));
    }
}
