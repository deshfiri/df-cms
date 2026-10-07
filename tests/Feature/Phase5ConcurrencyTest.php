<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItemCollection;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Services\BrandChecklistHoldService;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Phase 5 — regression coverage for two concurrency gaps a dedicated review
 * found and app/Services/ContentItemService.php::collect() and
 * app/Services/BrandChecklistHoldService.php::clearHold() were then fixed
 * for (both now lockForUpdate() + re-check inside the transaction, matching
 * ContentItemService::publish()'s existing "Fix G" pattern).
 *
 * PHPUnit can't run two requests at the literal same instant, so this uses
 * the same simulation the existing suite already relies on for this exact
 * purpose (see TaskSubmissionTest::test_the_locked_row_refuses_a_submission_
 * that_raced_past_the_policy): load two copies of the row, let the first
 * caller finish (commits, releases the lock), then make the second caller
 * — still holding its stale, pre-race copy — try the same action. A
 * genuinely locked path re-reads fresh state and refuses; an unlocked
 * check-then-act path would not have.
 */
class Phase5ConcurrencyTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manage payments', 'manage raw-content', 'manage smm-collection', 'manage publishing-review', 'view brand-checklist-overview'] as $perm) {
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

    private function readyBrand(User $manager): Brand
    {
        $category = Category::create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF'.uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $budget = Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 1000, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        Payment::create([
            'client_id' => $client->id, 'invoice_id' => $budget->id, 'payment_category_id' => $budget->payment_category_id,
            'amount' => 1000, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($budget->fresh());

        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 300, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    public function test_a_second_concurrent_collect_is_refused_once_the_first_has_already_collected_it(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smmA = $this->user('Social Media Manager', ['manage smm-collection']);
        $smmB = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);
        $service = app(ContentItemService::class);

        $item = $service->create($brand, ['category' => 'raw_content', 'title' => 'Race item'], $content);
        $this->submitItem($item, ['link_url' => 'https://example.com/v1.jpg'], $content);

        // Two SMM users both load the item while it's still AVAILABLE.
        $staleForA = $item->fresh();
        $staleForB = $item->fresh();

        $this->approveHandover($staleForA, $staleForA->latestSubmission(), $this->user('Marketing', ['manage publishing-review']));
        $service->collect($staleForA, $smmA); // wins the race, commits

        try {
            $service->collect($staleForB, $smmB); // loses it
            $this->fail('A second concurrent collect went through.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('item', $e->errors());
        }

        $this->assertSame(1, ContentItemCollection::where('content_item_id', $item->id)->count());
        $this->assertSame($smmA->id, ContentItemCollection::where('content_item_id', $item->id)->value('collected_by'));
    }

    public function test_a_second_concurrent_clear_hold_is_refused_once_the_first_has_already_cleared_it(): void
    {
        $managerA = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $managerB = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $brand = $this->readyBrand($managerA);
        $checklist = $brand->checklist;
        $checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Race test hold']);

        $service = app(BrandChecklistHoldService::class);
        $staleForA = BrandChecklist::find($checklist->id);
        $staleForB = BrandChecklist::find($checklist->id);

        $service->clearHold($staleForA, $managerA); // resolves and commits first

        try {
            $service->clearHold($staleForB, $managerB, 'Clearing it again just in case');
            $this->fail('A second concurrent clear-hold went through.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('hold', $e->errors());
        }

        $this->assertSame(
            1,
            ActivityLog::where('module', 'Brand Checklist Hold')
                ->whereIn('action', ['Resolved', 'Manually cleared'])
                ->count()
        );
    }
}
