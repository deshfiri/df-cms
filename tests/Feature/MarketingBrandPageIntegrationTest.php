<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\Product;
use App\Models\PublishedContent;
use App\Models\User;
use App\Services\AdvertisingExpenditureService;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UI integration gap fix: the Phase 3 backend (content-charge, advertising
 * expenditure, publishing review) had no browser UI anywhere — this wires
 * it into the existing GET /marketing/brands/{brand} page. These tests
 * exercise the exact routes/controllers the page's own JS now calls, plus
 * the two new minimal GET listing endpoints added alongside them
 * (content-charges.index, expenditures.index — neither existed before,
 * nothing else could have rendered a history list without one).
 */
class MarketingBrandPageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'view clients', 'manage clients', 'view ads', 'manage ads', 'manage products', 'manage payments',
            'manage content-charges', 'manage advertising-expenditure', 'manage publishing-review',
            'view brand-checklist-overview', 'manage raw-content', 'manage smm-collection', 'manage published-content',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        PaymentCategory::firstOrCreate(['name' => 'Social Media Ads'], ['is_active' => true, 'sort_order' => 10]);
        PaymentCategory::firstOrCreate(['name' => 'Content Production'], ['is_active' => true, 'sort_order' => 20]);
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

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF'.uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    private function marketing(): User
    {
        return $this->user('Marketing', [
            'view clients', 'view ads', 'manage ads', 'manage products',
            'manage content-charges', 'manage advertising-expenditure', 'manage publishing-review',
        ]);
    }

    private function fundAdBudget(Client $client, Brand $brand, User $actor, float $amount = 1000): void
    {
        $invoice = Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => $amount, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $actor->id, 'issued_date' => now(),
        ]);
        Payment::create([
            'client_id' => $client->id, 'invoice_id' => $invoice->id, 'payment_category_id' => $invoice->payment_category_id,
            'amount' => $amount, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $actor->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($invoice->fresh());
    }

    // ── 1-2-3: Content Charge, correct brand, triggers eligibility ─────────

    public function test_marketing_can_create_a_content_charge_through_the_browser_facing_workflow(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $response = $this->actingAs($marketing)->postJson(route('marketing.content-charge.store', $brand), [
            'total_payable' => 500, 'title' => 'October content',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('invoices', [
            'brand_id' => $brand->id, 'total_payable' => 500, 'title' => 'October content', 'status' => Invoice::STATUS_UNPAID,
        ]);
    }

    public function test_the_content_charge_listing_shows_it_against_the_correct_brand_only(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A '.uniqid()]);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B '.uniqid()]);

        $this->actingAs($marketing)->postJson(route('marketing.content-charge.store', $brandA), ['total_payable' => 300, 'title' => 'Alpha charge'])->assertOk();
        $this->actingAs($marketing)->postJson(route('marketing.content-charge.store', $brandB), ['total_payable' => 400, 'title' => 'Beta charge'])->assertOk();

        $listA = $this->actingAs($marketing)->getJson(route('marketing.content-charges.index', $brandA));
        $listA->assertOk();
        $titlesA = collect($listA->json('data'))->pluck('title')->all();
        $this->assertContains('Alpha charge', $titlesA);
        $this->assertNotContains('Beta charge', $titlesA);

        $listB = $this->actingAs($marketing)->getJson(route('marketing.content-charges.index', $brandB));
        $titlesB = collect($listB->json('data'))->pluck('title')->all();
        $this->assertContains('Beta charge', $titlesB);
        $this->assertNotContains('Alpha charge', $titlesB);
    }

    public function test_creating_the_charge_triggers_checklist_eligibility_when_ad_budget_is_already_paid(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);
        $this->fundAdBudget($client, $brand, $marketing);

        $this->assertNull($brand->fresh()->checklist);

        $this->actingAs($marketing)->postJson(route('marketing.content-charge.store', $brand), ['total_payable' => 300])->assertOk();

        $this->assertNotNull($brand->fresh()->checklist);
        $this->assertFalse($brand->fresh()->checklist->isOnHold());
    }

    // ── 4: Budget/Spent/Remaining/Overspent, checklist status ──────────────

    public function test_the_budget_endpoint_returns_correct_figures_and_checklist_status(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);
        $this->fundAdBudget($client, $brand, $marketing, 1000);
        $this->actingAs($marketing)->postJson(route('marketing.content-charge.store', $brand), ['total_payable' => 300])->assertOk();

        app(AdvertisingExpenditureService::class)->create($brand->fresh(), [
            'amount' => 1200, 'reporting_date' => now()->toDateString(),
        ], $marketing);

        $response = $this->actingAs($marketing)->getJson(route('marketing.advertising-budget', $brand));

        $response->assertOk();
        $response->assertJson([
            'budget' => 1000.0, 'spent' => 1200.0, 'remaining' => -200.0,
            'is_overspent' => true, 'overspent_amount' => 200.0,
            'checklist_status' => 'active',
        ]);
    }

    public function test_the_marketing_brand_page_renders_with_every_new_section_for_a_fully_permissioned_user(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $response = $this->actingAs($marketing)->get(route('marketing.brand', $brand));

        $response->assertOk();
        $response->assertSee('Content Charges');
        $response->assertSee('Advertising Budget');
        $response->assertSee('Advertising Expenditure');
        $response->assertSee('Publishing Review');
        $response->assertSee('Products'); // existing section still present
    }

    // ── 5-6: Expenditure create from the UI endpoint, Option 2 under hold ──

    public function test_marketing_can_create_expenditure_from_the_ui_backed_endpoint(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);
        $this->fundAdBudget($client, $brand, $marketing, 1000);

        $response = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 150, 'reporting_date' => now()->toDateString(),
        ]);

        $response->assertOk();
        $list = $this->actingAs($marketing)->getJson(route('marketing.expenditures.index', $brand));
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertEquals(150.0, $list->json('data.0.amount'));
    }

    public function test_option_2_expenditure_creation_remains_available_while_checklist_is_on_hold(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);
        $this->fundAdBudget($client, $brand, $marketing, 1000);
        $this->actingAs($marketing)->postJson(route('marketing.content-charge.store', $brand), ['total_payable' => 300])->assertOk();

        $checklist = $brand->fresh()->checklist;
        $checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Simulated hold']);

        $budget = $this->actingAs($marketing)->getJson(route('marketing.advertising-budget', $brand));
        $budget->assertJson(['checklist_status' => 'on_hold', 'has_available_budget' => true]);

        $response = $this->actingAs($marketing)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ]);
        $response->assertOk();
    }

    // ── 7-8: Publishing review listing + action ─────────────────────────────

    private function publishedItemFor(Brand $brand): PublishedContent
    {
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        BrandChecklist::firstOrCreate(['brand_id' => $brand->id]);
        $service = app(ContentItemService::class);
        $item = $service->create($brand, ['category' => 'raw_content', 'title' => 'Item '.uniqid()], $content);
        $submission = $service->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $service->collect($item->fresh(), $smm);

        return $service->publish($item->fresh(), $brand, $submission->fresh(), ['facebook_post_url' => 'https://facebook.com/post/'.uniqid()], $smm);
    }

    public function test_unreviewed_published_content_appears_for_the_correct_brand_only(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A '.uniqid()]);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B '.uniqid()]);
        $pubA = $this->publishedItemFor($brandA);
        $this->publishedItemFor($brandB);

        $listA = $this->actingAs($marketing)->getJson(route('marketing.published-contents.unreviewed', $brandA));
        $listA->assertOk();
        $this->assertCount(1, $listA->json('data'));
        $this->assertSame($pubA->id, $listA->json('data.0.id'));
    }

    public function test_marketing_can_review_the_published_content_and_it_leaves_the_unreviewed_queue(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);
        $published = $this->publishedItemFor($brand);

        $response = $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published]));
        $response->assertOk();
        $this->assertNotNull($published->fresh()->reviewed_at);

        $list = $this->actingAs($marketing)->getJson(route('marketing.published-contents.unreviewed', $brand));
        $this->assertCount(0, $list->json('data'));
    }

    // ── 9: Brand isolation across the new sections ──────────────────────────

    public function test_brand_a_data_never_appears_on_brand_bs_marketing_page_data(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A '.uniqid()]);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B '.uniqid()]);
        $this->fundAdBudget($client, $brandA, $marketing, 1000);
        $this->fundAdBudget($client, $brandB, $marketing, 2000);

        app(AdvertisingExpenditureService::class)->create($brandA->fresh(), ['amount' => 100, 'reporting_date' => now()->toDateString()], $marketing);
        app(AdvertisingExpenditureService::class)->create($brandB->fresh(), ['amount' => 400, 'reporting_date' => now()->toDateString()], $marketing);

        $expA = $this->actingAs($marketing)->getJson(route('marketing.expenditures.index', $brandA));
        $this->assertCount(1, $expA->json('data'));
        $this->assertEquals(100.0, $expA->json('data.0.amount'));

        $expB = $this->actingAs($marketing)->getJson(route('marketing.expenditures.index', $brandB));
        $this->assertCount(1, $expB->json('data'));
        $this->assertEquals(400.0, $expB->json('data.0.amount'));

        $budgetA = $this->actingAs($marketing)->getJson(route('marketing.advertising-budget', $brandA));
        $budgetA->assertJson(['budget' => 1000.0, 'spent' => 100.0]);
        $budgetB = $this->actingAs($marketing)->getJson(route('marketing.advertising-budget', $brandB));
        $budgetB->assertJson(['budget' => 2000.0, 'spent' => 400.0]);
    }

    // ── 10: Unauthorized roles ────────────────────────────────────────────

    public function test_a_role_without_content_charge_permission_cannot_list_or_create_charges(): void
    {
        $content = $this->user('Content', ['manage raw-content']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $this->actingAs($content)->postJson(route('marketing.content-charge.store', $brand), ['total_payable' => 100])->assertForbidden();
        $this->actingAs($content)->getJson(route('marketing.content-charges.index', $brand))->assertForbidden();
    }

    public function test_a_role_without_expenditure_permission_cannot_list_or_create_expenditure(): void
    {
        $content = $this->user('Content', ['manage raw-content']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $this->actingAs($content)->postJson(route('marketing.expenditures.store', $brand), ['amount' => 10, 'reporting_date' => now()->toDateString()])->assertForbidden();
        $this->actingAs($content)->getJson(route('marketing.expenditures.index', $brand))->assertForbidden();
    }

    public function test_a_role_without_publishing_review_permission_cannot_list_or_review(): void
    {
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);
        $published = $this->publishedItemFor($brand);

        $this->actingAs($smm)->getJson(route('marketing.published-contents.unreviewed', $brand))->assertForbidden();
        $this->actingAs($smm)->postJson(route('marketing.published-contents.review', [$brand, $published]))->assertForbidden();
    }

    public function test_the_marketing_brand_page_hides_new_sections_from_a_user_without_those_permissions(): void
    {
        // A Marketing-adjacent user with only the base ads permission, none of
        // the three new Phase 3 permissions — server-side enforcement is what
        // actually matters (proven above); this confirms the buttons are also
        // not rendered for someone who could never use them anyway.
        $viewer = $this->user('Viewer', ['view clients', 'view ads', 'manage ads']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $response = $this->actingAs($viewer)->get(route('marketing.brand', $brand));

        // Check the actual gated markup (button ids), not prose text — the
        // page's inert JS comments mention these section names unconditionally
        // (comments are never executed, and never shown to a user), so a plain
        // text search would false-positive on them.
        $response->assertOk();
        $response->assertDontSee('id="mkAddChargeBtn"', false);
        $response->assertDontSee('id="mkAddExpenseBtn"', false);
        $response->assertDontSee('id="mkReviewRows"', false);
    }

    // ── Manual-QA bug: the Products "+" button on this exact page ───────────

    public function test_an_authorized_user_can_add_a_product_to_a_brand(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $response = $this->actingAs($marketing)->postJson(route('marketing.products.store', $brand), ['name' => 'Test Product']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Test Product');
        $response->assertJsonPath('data.brand_id', $brand->id);
        $this->assertDatabaseHas('products', ['brand_id' => $brand->id, 'name' => 'Test Product']);
    }

    public function test_a_product_added_to_one_brand_is_not_visible_on_another_brand(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A '.uniqid()]);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B '.uniqid()]);

        $this->actingAs($marketing)->postJson(route('marketing.products.store', $brandA), ['name' => 'Only On A'])->assertOk();

        $indexB = $this->actingAs($marketing)->getJson(route('marketing.products.index', $brandB));
        $indexB->assertOk();
        $this->assertSame([], $indexB->json('data'));

        $indexA = $this->actingAs($marketing)->getJson(route('marketing.products.index', $brandA));
        $indexA->assertOk();
        $this->assertCount(1, $indexA->json('data'));
    }

    public function test_an_empty_or_invalid_product_name_is_rejected(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $this->actingAs($marketing)->postJson(route('marketing.products.store', $brand), ['name' => ''])->assertStatus(422);
        $this->actingAs($marketing)->postJson(route('marketing.products.store', $brand), [])->assertStatus(422);

        // Existing validation rule: unique per brand — already-covered
        // Product CRUD behavior, confirmed still intact alongside the fix.
        $this->actingAs($marketing)->postJson(route('marketing.products.store', $brand), ['name' => 'Dup'])->assertOk();
        $this->actingAs($marketing)->postJson(route('marketing.products.store', $brand), ['name' => 'Dup'])->assertStatus(422);
    }

    public function test_a_user_without_manage_products_cannot_add_a_product(): void
    {
        $viewer = $this->user('Viewer', ['view clients', 'view ads']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $response = $this->actingAs($viewer)->postJson(route('marketing.products.store', $brand), ['name' => 'Nope']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('products', ['brand_id' => $brand->id, 'name' => 'Nope']);
    }

    public function test_a_guest_cannot_add_a_product(): void
    {
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $response = $this->post(route('marketing.products.store', $brand), ['name' => 'Nope']);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('products', ['brand_id' => $brand->id, 'name' => 'Nope']);
    }

    public function test_a_product_cannot_be_tampered_into_belonging_to_a_different_brand(): void
    {
        // store() takes the brand from the route itself, never from request
        // data — there is no brand_id field in the validated payload for an
        // attacker to override. Confirmed by trying to smuggle one in.
        $marketing = $this->marketing();
        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A '.uniqid()]);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B '.uniqid()]);

        $response = $this->actingAs($marketing)->postJson(
            route('marketing.products.store', $brandA),
            ['name' => 'Smuggled', 'brand_id' => $brandB->id]
        );

        $response->assertOk();
        $this->assertDatabaseHas('products', ['brand_id' => $brandA->id, 'name' => 'Smuggled']);
        $this->assertDatabaseMissing('products', ['brand_id' => $brandB->id, 'name' => 'Smuggled']);
    }

    public function test_existing_product_update_and_delete_still_work(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);
        $product = $this->actingAs($marketing)->postJson(route('marketing.products.store', $brand), ['name' => 'Original']);
        $product = Product::where('brand_id', $brand->id)->where('name', 'Original')->firstOrFail();

        $this->actingAs($marketing)->putJson(route('marketing.products.update', [$brand, $product]), [
            'name' => 'Renamed', 'is_active' => false,
        ])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Renamed', 'is_active' => false]);

        $this->actingAs($marketing)->deleteJson(route('marketing.products.destroy', [$brand, $product]))->assertOk();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    /**
     * The bug this whole section exists for: a console TypeError from an
     * unrelated, globally-included realtime widget (see layouts/app.blade.php
     * — vendor/js/echo.iife.js leaks a bare, uninstantiated Echo class onto
     * window.Echo even with Reverb disabled) was suspected of silently
     * breaking this page's own JS. It doesn't — the Products section's own
     * script tag finishes binding its handlers before that widget's script
     * even runs — but the underlying leak is real and is fixed at its one
     * source (app.blade.php nulls window.Echo when Reverb isn't ready).
     * PHPUnit can't execute browser JS to re-prove the click handler itself,
     * so this instead asserts the actual server-rendered contract every
     * `if (window.Echo)` guard across the app depends on: that the page
     * renders normally, every control is present, and Echo is explicitly
     * neutralized rather than left as a truthy non-instance.
     */
    public function test_the_marketing_brand_page_renders_normally_with_realtime_disabled(): void
    {
        $marketing = $this->marketing();
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        config(['broadcasting.connections.reverb.key' => '', 'broadcasting.connections.reverb.options.host' => '']);

        $response = $this->actingAs($marketing)->get(route('marketing.brand', $brand));

        $response->assertOk();
        $response->assertSee('window.RealtimeReady = false', false);
        $response->assertSee('window.Echo = null;', false);
        $response->assertSee('id="mkAddProduct"', false);
        $response->assertSee('id="mkNewProduct"', false);
    }
}
