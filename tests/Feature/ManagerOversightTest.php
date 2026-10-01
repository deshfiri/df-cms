<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4 — Manager oversight: brand-wise budget table, checklist status
 * overview with the two-path clear-hold action (Fix I), and department
 * workload. See C:\Users\DST\.claude\plans\soft-nibbling-quilt.md.
 */
class ManagerOversightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view brand-checklist-overview', 'guard_name' => 'web']);
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
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    private function payAdBudget(Brand $brand, User $actor, float $amount): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $brand->client_id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => $amount, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $actor->id, 'issued_date' => now(),
        ]);
        Payment::create([
            'client_id' => $brand->client_id, 'invoice_id' => $invoice->id, 'payment_category_id' => $invoice->payment_category_id,
            'amount' => $amount, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $actor->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($invoice->fresh());

        return $invoice->fresh();
    }

    /** A brand with both a paid ad budget and an active content charge — InvoiceObserver creates its checklist. */
    private function readyBrand(User $actor, float $amount = 1000): Brand
    {
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $this->payAdBudget($brand, $actor, $amount);
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 300, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $actor->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    public function test_manager_can_reach_the_oversight_screen(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);

        $response = $this->actingAs($manager)->get(route('manager.oversight'));

        $response->assertOk();
        $response->assertViewIs('manager.oversight');
    }

    public function test_user_without_the_permission_is_forbidden(): void
    {
        $content = $this->user('Content');

        $response = $this->actingAs($content)->get(route('manager.oversight'));

        $response->assertForbidden();
    }

    public function test_budget_table_matches_brand_methods_directly(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);
        $brand = $this->readyBrand($manager, 1000);

        $response = $this->actingAs($manager)->get(route('manager.oversight'));

        $response->assertOk();
        $response->assertViewHas('budgets', function ($budgets) use ($brand) {
            $row = collect($budgets)->firstWhere(fn ($r) => $r['brand']->id === $brand->id);

            return $row
                && (float) $row['budget'] === $brand->advertisingBudget()
                && (float) $row['remaining'] === $brand->advertisingRemaining()
                && $row['is_overspent'] === $brand->isAdvertisingOverspent();
        });
    }

    public function test_checklist_overview_shows_the_hold_reason(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);
        $brand = $this->readyBrand($manager, 1000);
        $checklist = BrandChecklist::where('brand_id', $brand->id)->firstOrFail();
        $checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Test hold reason']);

        $response = $this->actingAs($manager)->get(route('manager.oversight'));

        $response->assertOk();
        $response->assertSee('Test hold reason');
    }

    public function test_clearing_a_hold_resolves_automatically_when_the_condition_is_met_again(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);
        $brand = $this->readyBrand($manager, 1000);
        $checklist = BrandChecklist::where('brand_id', $brand->id)->firstOrFail();
        // Simulate the observer's own hold (bypassing an actual refund flow — Phase 3's
        // tests establish the refund → hold chain already works end to end).
        $checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Simulated hold']);

        $response = $this->actingAs($manager)->postJson(route('manager.checklists.clear-hold', $checklist));

        $response->assertOk();
        $response->assertJson(['success' => true, 'cleared' => true, 'method' => 'resolved']);
        $this->assertNull($checklist->fresh()->on_hold_at);
    }

    public function test_clearing_a_hold_without_a_reason_is_refused_when_still_unresolved(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        // No paid ad budget and no content charge at all — condition is genuinely unresolved.
        $checklist = BrandChecklist::create(['brand_id' => $brand->id, 'on_hold_at' => now(), 'on_hold_reason' => 'Never funded']);

        $response = $this->actingAs($manager)->postJson(route('manager.checklists.clear-hold', $checklist));

        $response->assertStatus(422);
        $this->assertNotNull($checklist->fresh()->on_hold_at);
    }

    public function test_manual_override_with_a_reason_clears_an_unresolved_hold_and_is_logged_distinctly(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
        $checklist = BrandChecklist::create(['brand_id' => $brand->id, 'on_hold_at' => now(), 'on_hold_reason' => 'Never funded']);

        $response = $this->actingAs($manager)->postJson(route('manager.checklists.clear-hold', $checklist), [
            'reason' => 'Client paid by bank transfer, invoice still pending — clearing manually.',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'cleared' => true, 'method' => 'manual_override']);
        $this->assertNull($checklist->fresh()->on_hold_at);
        $this->assertDatabaseHas('activity_logs', ['module' => 'Brand Checklist Hold', 'action' => 'Manually cleared']);
    }

    public function test_non_manager_cannot_clear_a_hold(): void
    {
        $content = $this->user('Content');
        $manager = $this->user('Manager', ['view brand-checklist-overview']);
        $brand = $this->readyBrand($manager, 1000);
        $checklist = BrandChecklist::where('brand_id', $brand->id)->firstOrFail();
        $checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Simulated hold']);

        $response = $this->actingAs($content)->postJson(route('manager.checklists.clear-hold', $checklist));

        $response->assertForbidden();
    }

    public function test_department_workload_counts_pending_content_items(): void
    {
        $manager = $this->user('Manager', ['view brand-checklist-overview']);
        $brand = $this->readyBrand($manager, 1000);
        $checklist = BrandChecklist::where('brand_id', $brand->id)->firstOrFail();

        ContentItem::create([
            'checklist_id' => $checklist->id, 'brand_id' => $brand->id,
            'category' => ContentItem::CATEGORY_RAW_CONTENT, 'status' => ContentItem::STATUS_PENDING,
            'title' => 'Item A', 'created_by' => $manager->id,
        ]);
        ContentItem::create([
            'checklist_id' => $checklist->id, 'brand_id' => $brand->id,
            'category' => ContentItem::CATEGORY_POSTER, 'status' => ContentItem::STATUS_IN_PROGRESS,
            'title' => 'Item B', 'created_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager)->get(route('manager.oversight'));

        $response->assertOk();
        $response->assertViewHas('workload', function ($workload) {
            return $workload['content']['owed'] === 1 && $workload['designer']['owed'] === 1;
        });
    }
}
