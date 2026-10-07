<?php

namespace Tests\Feature;

use App\Models\AdvertisingExpenditure;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\Product;
use App\Models\User;
use App\Services\AdvertisingExpenditureService;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Phase 5 — final integration hardening. Two things Phases 0-4's own test
 * files never did: (1) walk the ENTIRE lifecycle as one continuous scenario
 * instead of phase-by-phase slices, and (2) run that lifecycle twice, for
 * two Brands under the SAME Client, asserting neither brand's data is ever
 * visible through the other's queries/endpoints.
 */
class Phase5IntegrationTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'view clients', 'manage payments', 'manage products',
            'view raw-content-panel', 'manage raw-content', 'view designer-panel', 'manage designer-content',
            'view smm-panel', 'manage smm-collection', 'manage published-content',
            'manage content-charges', 'manage advertising-expenditure', 'manage publishing-review',
            'view brand-checklist-overview',
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
            'dfid_number' => 'DF'.uniqid(), 'client_name' => 'Shared Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    /** Funds a brand's advertising budget and content-charge invoice, triggering checklist auto-creation. */
    private function fundBrand(Client $client, Brand $brand, User $manager, float $adBudget = 1000, float $contentCharge = 300): void
    {
        $ad = Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => $adBudget, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        Payment::create([
            'client_id' => $client->id, 'invoice_id' => $ad->id, 'payment_category_id' => $ad->payment_category_id,
            'amount' => $adBudget, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($ad->fresh());

        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => $contentCharge, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
    }

    /** Walks one brand all the way from an empty checklist to a reviewed publish + recorded expenditure. */
    private function runFullLifecycle(Brand $brand, User $content, User $smm, User $marketing): array
    {
        $product = Product::create(['brand_id' => $brand->id, 'name' => 'Product '.uniqid(), 'created_by' => $content->id]);

        $item = app(ContentItemService::class)->create($brand, [
            'category' => 'raw_content', 'title' => 'Item '.uniqid(), 'product_id' => $product->id,
        ], $content);

        $submission = $this->submitItem($item, ['link_url' => 'https://example.com/v1.jpg'], $content);

        $this->approveHandover($item->fresh(), $submission->fresh(), $marketing);
        $collection = app(ContentItemService::class)->collect($item->fresh(), $smm);

        $published = app(ContentItemService::class)->publish(
            $item->fresh(), $brand, $submission->fresh(),
            ['facebook_post_url' => 'https://facebook.com/post/'.uniqid()], $smm
        );

        $expenditure = app(AdvertisingExpenditureService::class)->create($brand, [
            'amount' => 150, 'reporting_date' => now()->toDateString(),
        ], $marketing);

        return compact('product', 'item', 'submission', 'collection', 'published', 'expenditure');
    }

    // ── 1. Full end-to-end lifecycle ─────────────────────────────────────

    public function test_full_lifecycle_from_brand_creation_to_manager_oversight(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage content-charges', 'manage advertising-expenditure', 'manage publishing-review']);

        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Lifecycle Brand']);

        // No checklist until both invoices exist — the trigger itself, exercised live.
        $this->assertNull($brand->checklist);

        $this->fundBrand($client, $brand, $manager);
        $brand->refresh();
        $this->assertNotNull($brand->checklist);
        $this->assertFalse($brand->checklist->isOnHold());

        $result = $this->runFullLifecycle($brand, $content, $smm, $marketing);

        $this->assertSame(ContentItem::STATUS_PUBLISHED, $result['item']->fresh()->status);
        $this->assertDatabaseHas('published_contents', ['id' => $result['published']->id, 'reviewed_at' => null]);

        // Marketing review — the one remaining step in the chain.
        $marketingUser = $marketing;
        $reviewResponse = $this->reviewPublication($brand, $result['published'], $marketingUser);
        $reviewResponse->assertOk();
        $this->assertNotNull($result['published']->fresh()->reviewed_at);

        // Manager oversight reflects all of it.
        $oversight = $this->actingAs($manager)->get(route('manager.oversight'));
        $oversight->assertOk();
        $oversight->assertViewHas('budgets', function ($budgets) use ($brand) {
            $row = collect($budgets)->firstWhere(fn ($r) => $r['brand']->id === $brand->id);

            return $row && (float) $row['spent'] === 150.0;
        });
        $oversight->assertViewHas('checklists', fn ($checklists) => $checklists->firstWhere('brand_id', $brand->id) !== null);
    }

    // ── 2. Multi-brand isolation under the same Client ───────────────────

    public function test_two_brands_under_the_same_client_never_leak_into_each_other(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content', 'view raw-content-panel']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage content-charges', 'manage advertising-expenditure', 'manage publishing-review']);

        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B']);

        $this->fundBrand($client, $brandA, $manager, adBudget: 1000, contentCharge: 300);
        $this->fundBrand($client, $brandB, $manager, adBudget: 2000, contentCharge: 400);
        $brandA->refresh();
        $brandB->refresh();

        $resultA = $this->runFullLifecycle($brandA, $content, $smm, $marketing);
        $resultB = $this->runFullLifecycle($brandB, $content, $smm, $marketing);

        // ── Invoices / budgets / expenditures ──
        $this->assertSame(1000.0, $brandA->advertisingBudget());
        $this->assertSame(2000.0, $brandB->advertisingBudget());
        $this->assertSame(150.0, $brandA->advertisingSpent());
        $this->assertSame(150.0, $brandB->advertisingSpent());
        $this->assertEqualsWithDelta(850.0, $brandA->advertisingRemaining(), 0.001);
        $this->assertEqualsWithDelta(1850.0, $brandB->advertisingRemaining(), 0.001);

        foreach (Invoice::where('brand_id', $brandA->id)->get() as $inv) {
            $this->assertNotEquals($brandB->id, $inv->brand_id);
        }
        $this->assertSame(1, AdvertisingExpenditure::where('brand_id', $brandA->id)->count());
        $this->assertSame(1, AdvertisingExpenditure::where('brand_id', $brandB->id)->count());
        $this->assertNotEquals(
            AdvertisingExpenditure::where('brand_id', $brandA->id)->value('id'),
            AdvertisingExpenditure::where('brand_id', $brandB->id)->value('id'),
        );

        // ── Checklists ──
        $this->assertNotEquals($brandA->checklist->id, $brandB->checklist->id);
        $this->assertSame($brandA->id, $brandA->checklist->brand_id);
        $this->assertSame($brandB->id, $brandB->checklist->brand_id);

        // Putting Brand A on hold must never affect Brand B's checklist.
        // Brand A's publication is claimed for review before the hold, as a reviewer
        // would have done. Reviewing an already-owned pre-hold publish stays allowed.
        $this->claimFinalReview($resultA['published'], $marketing);
        $brandA->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Isolation test hold']);
        $this->assertFalse($brandB->checklist->fresh()->isOnHold());
        $this->assertTrue($brandA->checklist->fresh()->isOnHold());

        // ── Content items / submissions ──
        $this->assertSame($brandA->id, $resultA['item']->brand_id);
        $this->assertSame($brandB->id, $resultB['item']->brand_id);
        $this->assertNotEquals($resultA['item']->id, $resultB['item']->id);
        $this->assertSame($resultA['item']->id, $resultA['submission']->content_item_id);
        $this->assertNotEquals($resultA['submission']->content_item_id, $resultB['item']->id);

        // ── SMM collections / publishes ──
        $this->assertSame($resultA['item']->id, $resultA['collection']->content_item_id);
        $this->assertNotEquals($resultA['collection']->content_item_id, $resultB['item']->id);
        $this->assertSame($brandA->id, $resultA['published']->brand_id);
        $this->assertSame($brandB->id, $resultB['published']->brand_id);
        $this->assertNotEquals($resultA['published']->id, $resultB['published']->id);

        // ── Publishing reviews ──
        $this->reviewPublication($brandA, $resultA['published'], $marketing)->assertOk();
        $this->assertNotNull($resultA['published']->fresh()->reviewed_at);
        $this->assertNull($resultB['published']->fresh()->reviewed_at);

        $unreviewedA = $this->actingAs($marketing)->getJson(route('marketing.published-contents.unreviewed', $brandA));
        $this->assertEmpty($unreviewedA->json('data'));
        $unreviewedB = $this->actingAs($marketing)->getJson(route('marketing.published-contents.unreviewed', $brandB));
        $this->assertCount(1, $unreviewedB->json('data'));
        $this->assertSame($resultB['published']->id, $unreviewedB->json('data.0.id'));

        // ── Products ──
        $this->assertSame($brandA->id, $resultA['product']->brand_id);
        $this->assertNotEquals($resultA['product']->id, $resultB['product']->id);
        $this->assertCount(1, $brandA->products()->get());
        $this->assertCount(1, $brandB->products()->get());

        // Listing Brand A's content items must never return Brand B's.
        $indexA = $this->actingAs($content)->getJson(route('marketing.content-items.index', $brandA));
        $idsA = collect($indexA->json('data'))->pluck('id')->all();
        $this->assertContains($resultA['item']->id, $idsA);
        $this->assertNotContains($resultB['item']->id, $idsA);
    }

    public function test_an_expenditure_on_one_brand_never_affects_the_others_remaining_budget(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $client = $this->client();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B']);
        $this->fundBrand($client, $brandA, $manager, 1000, 300);
        $this->fundBrand($client, $brandB, $manager, 1000, 300);

        app(AdvertisingExpenditureService::class)->create($brandA->fresh(), [
            'amount' => 900, 'reporting_date' => now()->toDateString(),
        ], $marketing);

        $this->assertTrue($brandA->fresh()->isAdvertisingOverspent() === false); // 900 of 1000, not yet overspent
        $this->assertSame(1000.0, $brandB->fresh()->advertisingBudget());
        $this->assertSame(0.0, $brandB->fresh()->advertisingSpent());
        $this->assertSame(1000.0, $brandB->fresh()->advertisingRemaining());
    }
}
