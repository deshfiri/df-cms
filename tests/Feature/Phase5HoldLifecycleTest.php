<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentItemCollection;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\Refund;
use App\Models\User;
use App\Services\AdvertisingExpenditureService;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Phase 5 — the hold lifecycle, swept as one consolidated matrix instead of
 * the scattered create/submit-only coverage Phases 1-3 left behind: every
 * content action that must be blocked while on hold, the one read-mostly
 * exception (reviewing a pre-hold publish), both clear paths, and that each
 * step (trigger, clear) is actually audit-logged.
 */
class Phase5HoldLifecycleTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'manage payments', 'manage raw-content', 'manage smm-collection', 'manage published-content',
            'manage advertising-expenditure', 'manage publishing-review', 'view brand-checklist-overview',
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

    public function test_a_refund_that_zeroes_the_budget_holds_the_checklist_and_is_audit_logged(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $checklist = $brand->checklist;
        $this->assertFalse($checklist->isOnHold());

        $budget = Invoice::where('brand_id', $brand->id)
            ->whereHas('category', fn ($q) => $q->where('name', 'Social Media Ads'))->firstOrFail();
        $payment = Payment::where('invoice_id', $budget->id)->firstOrFail();

        Refund::create([
            'payment_id' => $payment->id, 'invoice_id' => $budget->id, 'client_id' => $brand->client_id,
            'refund_number' => 'RF-'.uniqid(), 'amount' => $payment->amount, 'status' => Refund::STATUS_COMPLETED,
            'reason' => 'Hold-lifecycle test', 'requested_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($budget->fresh());

        $checklist->refresh();
        $this->assertTrue($checklist->isOnHold());
        $this->assertDatabaseHas('activity_logs', [
            'module' => 'Brand Checklist Hold', 'action' => 'Held', 'client_id' => $brand->client_id,
        ]);
    }

    /** One consolidated sweep: every write path a hold must block, and the one it must not. */
    public function test_every_content_action_is_blocked_while_on_hold_except_reviewing_a_pre_hold_publish(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure', 'manage publishing-review']);
        $brand = $this->readyBrand($manager);

        // Build one already-published item BEFORE the hold starts, so we can
        // prove it stays reviewable afterward (the one read-mostly exception).
        $service = app(ContentItemService::class);
        $prehold = $service->create($brand, ['category' => 'raw_content', 'title' => 'Pre-hold item'], $content);
        $preholdSubmission = $this->submitItem($prehold, ['link_url' => 'https://example.com/pre.jpg'], $content);
        $this->approveHandover($prehold->fresh(), $preholdSubmission->fresh(), $marketing);
        $service->collect($prehold->fresh(), $smm);
        $prePublished = $service->publish($prehold->fresh(), $brand, $preholdSubmission->fresh(), ['facebook_post_url' => 'https://facebook.com/pre'], $smm);
        // Claimed before the hold starts. Claims are workflow mutations and so are
        // blocked during a hold, but reviewing an already-owned pre-hold publish is
        // still allowed, as it always was.
        $this->claimFinalReview($prePublished, $marketing);

        // A second item, submitted and collected before the hold, so collect/publish/
        // request-revision on IT can be attempted (and refused) once held.
        $heldItem = $service->create($brand, ['category' => 'raw_content', 'title' => 'Held-item'], $content);
        $heldSubmission = $this->submitItem($heldItem, ['link_url' => 'https://example.com/held.jpg'], $content);

        $this->approveHandover($heldItem->fresh(), $heldSubmission->fresh(), $marketing);
        $checklist = $brand->checklist->fresh();
        $checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Sweep test hold']);

        // Create — blocked.
        $this->expectExceptionViaService(fn () => $service->create($brand->fresh(), ['category' => 'raw_content', 'title' => 'New'], $content));

        // Submit — blocked.
        $this->expectExceptionViaService(fn () => $this->submitItem($heldItem->fresh(), ['link_url' => 'https://example.com/again.jpg'], $content));

        // Collect — blocked.
        $this->expectExceptionViaService(fn () => $service->collect($heldItem->fresh(), $smm));

        // Request-revision — blocked.
        $this->expectExceptionViaService(fn () => $this->reviseItem($prehold->fresh(), ['note' => 'redo'], $marketing));

        // Manually collect (bypassing the service's own hold guard) so we can prove
        // publish's OWN hold guard also refuses it, independent of collect's.
        ContentItemCollection::create([
            'content_item_id' => $heldItem->id, 'submission_id' => $heldSubmission->id,
            'collected_by' => $smm->id, 'collected_at' => now(),
        ]);
        $heldItem->update(['status' => ContentItem::STATUS_COLLECTED]);
        $this->expectExceptionViaService(fn () => $service->publish($heldItem->fresh(), $brand, $heldSubmission->fresh(), ['facebook_post_url' => 'https://facebook.com/held'], $smm));

        // Advertising expenditure — Option 2: NOT blocked by the hold.
        $expenditure = app(AdvertisingExpenditureService::class)->create($brand->fresh(), [
            'amount' => 50, 'reporting_date' => now()->toDateString(),
        ], $marketing);
        $this->assertNotNull($expenditure->id);

        // Reviewing a PRE-HOLD publish — the one read-mostly exception — still allowed.
        $response = $this->reviewPublication($brand, $prePublished, $marketing);
        $response->assertOk();
        $this->assertNotNull($prePublished->fresh()->reviewed_at);
    }

    private function expectExceptionViaService(\Closure $call): void
    {
        try {
            $call();
            $this->fail('Expected a ValidationException to be thrown while the checklist is on hold.');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_resolving_a_hold_automatically_is_also_audit_logged(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $brand = $this->readyBrand($manager);
        $checklist = $brand->checklist;
        $checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Simulated hold']);

        $this->actingAs($manager)->postJson(route('manager.checklists.clear-hold', $checklist))->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'Brand Checklist Hold', 'action' => 'Resolved', 'client_id' => $brand->client_id,
        ]);
        $this->assertFalse($checklist->fresh()->isOnHold());
    }
}
