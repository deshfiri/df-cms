<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Phase 5 — content version integrity across a full revision cycle. Phases
 * 1-2 already proved a revision resets status and that publishing a
 * superseded submission is refused; what's new here is tracing the OLD
 * submission's collection row and the OLD publish's review state through a
 * second full round, to prove nothing under history is ever overwritten.
 */
class Phase5ContentIntegrityTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manage payments', 'manage raw-content', 'manage smm-collection', 'manage published-content', 'manage publishing-review'] as $perm) {
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

    public function test_the_old_rounds_collection_and_review_rows_survive_untouched_through_a_full_revision_cycle(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        $service = app(ContentItemService::class);

        // Round 1.
        $item = $service->create($brand, ['category' => 'raw_content', 'title' => 'Versioned item'], $content);
        $submission1 = $this->submitItem($item, ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->approveHandover($item->fresh(), $item->fresh()->latestSubmission(), $this->user('Marketing', ['manage publishing-review']));
        $collection1 = $service->collect($item->fresh(), $smm);
        $published1 = $service->publish($item->fresh(), $brand, $submission1->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);

        $this->reviewPublication($brand, $published1, $marketing)->assertOk();
        $published1->refresh();
        $this->assertNotNull($published1->reviewed_at);
        $reviewedAtRound1 = $published1->reviewed_at;

        // Revision reopens the item — round 1's rows must not move.
        $this->reviseItem($item->fresh(), ['note' => 'Please redo'], $marketing);
        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
        $this->assertSame($submission1->id, $collection1->fresh()->submission_id);
        $this->assertSame('https://facebook.com/v1', $published1->fresh()->facebook_post_url);
        $this->assertEquals($reviewedAtRound1->timestamp, $published1->fresh()->reviewed_at->timestamp);

        // Round 2 — a fresh submission needs its own fresh collect + publish.
        $submission2 = $this->submitItem($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);
        $this->assertNotEquals($submission1->id, $submission2->id);

        $this->approveHandover($item->fresh(), $item->fresh()->latestSubmission(), $this->user('Marketing', ['manage publishing-review']));
        $collection2 = $service->collect($item->fresh(), $smm);
        $this->assertNotEquals($collection1->id, $collection2->id);
        $this->assertSame($submission2->id, $collection2->submission_id);

        $published2 = $service->publish($item->fresh(), $brand, $submission2->fresh(), ['facebook_post_url' => 'https://facebook.com/v2'], $smm);
        $this->assertNotEquals($published1->id, $published2->id);
        $this->assertNull($published2->reviewed_at);

        // Round 1's collection and publish rows are still exactly as they were.
        $this->assertDatabaseHas('content_item_collections', [
            'id' => $collection1->id, 'submission_id' => $submission1->id, 'collected_by' => $smm->id,
        ]);
        $this->assertDatabaseHas('published_contents', [
            'id' => $published1->id, 'submission_id' => $submission1->id, 'facebook_post_url' => 'https://facebook.com/v1',
        ]);
        $this->assertEquals($reviewedAtRound1->timestamp, $published1->fresh()->reviewed_at->timestamp);

        // Both submissions, both collections, both publishes coexist — nothing was overwritten in place.
        $this->assertDatabaseCount('content_item_submissions', 2);
        $this->assertDatabaseCount('content_item_collections', 2);
        $this->assertDatabaseCount('published_contents', 2);

        // The superseded submission can never be (re-)published.
        $response = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission1->id, 'facebook_post_url' => 'https://facebook.com/v1-again',
        ]);
        $response->assertStatus(422);
    }
}
