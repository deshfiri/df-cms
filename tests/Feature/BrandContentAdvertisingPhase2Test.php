<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentItemCollection;
use App\Models\ContentItemSubmission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Notifications\ChecklistRevisionRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2 of the Brand Content & Advertising DMS integration: the Raw
 * Content / Designer / SMM panels — cross-brand queues on top of Phase 1's
 * checklist engine, plus collect/publish (Fix G's staleness revalidation)
 * and the revision-request notification. See
 * C:\Users\DST\.claude\plans\soft-nibbling-quilt.md.
 */
class BrandContentAdvertisingPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach ([
            'view clients', 'manage payments',
            'view raw-content-panel', 'manage raw-content',
            'view designer-panel', 'manage designer-content',
            'view smm-panel', 'manage smm-collection', 'manage published-content',
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

    /** A brand with a live (not on-hold) checklist, ready for content items. */
    private function readyBrand(User $manager): Brand
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);

        $budget = Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 1000, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        Payment::create([
            'client_id' => $client->id, 'invoice_id' => $budget->id,
            'payment_category_id' => $budget->payment_category_id, 'amount' => 1000,
            'status' => 'Paid', 'payment_date' => now(), 'created_by' => $manager->id,
        ]);
        app(\App\Services\InvoiceService::class)->recalculateStatus($budget->fresh());

        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 500, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    private function itemWithSubmission(Brand $brand, User $content, string $category = 'raw_content'): ContentItem
    {
        $item = app(\App\Services\ContentItemService::class)->create($brand, [
            'category' => $category, 'title' => 'Item ' . uniqid(),
        ], $content);

        app(\App\Services\ContentItemService::class)->submit($item, ['link_url' => 'https://example.com/v1.jpg'], $content);

        return $item->fresh();
    }

    // ── Panel access ─────────────────────────────────────────────────────

    public function test_raw_content_panel_is_visible_to_content_role_only(): void
    {
        $content = $this->user('Content', ['view raw-content-panel']);
        $sales = $this->user('Sales');

        $this->actingAs($content)->get(route('panels.raw-content'))->assertOk();
        $this->actingAs($sales)->get(route('panels.raw-content'))->assertForbidden();
    }

    public function test_designer_panel_is_visible_to_design_role_only(): void
    {
        $design = $this->user('Design', ['view designer-panel']);
        $sales = $this->user('Sales');

        $this->actingAs($design)->get(route('panels.designer'))->assertOk();
        $this->actingAs($sales)->get(route('panels.designer'))->assertForbidden();
    }

    public function test_smm_panel_is_visible_to_smm_role_only(): void
    {
        $smm = $this->user('Social Media Manager', ['view smm-panel']);
        $sales = $this->user('Sales');

        $this->actingAs($smm)->get(route('panels.smm'))->assertOk();
        $this->actingAs($sales)->get(route('panels.smm'))->assertForbidden();
    }

    // ── Cross-brand listing ──────────────────────────────────────────────

    public function test_raw_content_panel_lists_items_across_every_brand(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['view raw-content-panel', 'manage raw-content']);
        $brandA = $this->readyBrand($manager);
        $brandB = $this->readyBrand($manager);

        app(\App\Services\ContentItemService::class)->create($brandA, ['category' => 'raw_content', 'title' => 'A item'], $content);
        app(\App\Services\ContentItemService::class)->create($brandB, ['category' => 'advertising_content', 'title' => 'B item'], $content);

        $response = $this->actingAs($content)->getJson(route('panels.raw-content'));
        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('A item', $titles);
        $this->assertContains('B item', $titles);
    }

    public function test_raw_content_panel_never_lists_poster_items(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['view raw-content-panel', 'manage raw-content']);
        $design = $this->user('Design', ['manage designer-content']);
        $brand = $this->readyBrand($manager);

        app(\App\Services\ContentItemService::class)->create($brand, ['category' => 'poster', 'title' => 'A poster'], $design);

        $response = $this->actingAs($content)->getJson(route('panels.raw-content'));
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertNotContains('A poster', $titles);
    }

    // ── Collect (Fix B) ──────────────────────────────────────────────────

    public function test_smm_can_collect_an_available_item(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);
        $item = $this->itemWithSubmission($brand, $content);

        $response = $this->actingAs($smm)->postJson(route('marketing.content-items.collect', [$brand, $item]));

        $response->assertOk();
        $this->assertSame(ContentItem::STATUS_COLLECTED, $item->fresh()->status);
        $this->assertDatabaseHas('content_item_collections', [
            'content_item_id' => $item->id, 'collected_by' => $smm->id,
        ]);
    }

    public function test_collecting_an_item_that_is_not_available_is_refused(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);
        $item = app(\App\Services\ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Not submitted'], $content);

        $response = $this->actingAs($smm)->postJson(route('marketing.content-items.collect', [$brand, $item]));

        $response->assertStatus(422);
    }

    public function test_a_role_without_smm_collection_permission_cannot_collect(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->itemWithSubmission($brand, $content);

        $response = $this->actingAs($content)->postJson(route('marketing.content-items.collect', [$brand, $item]));

        $response->assertForbidden();
    }

    // ── Publish (Fix G) ──────────────────────────────────────────────────

    private function collectedItem(Brand $brand, User $content, User $smm): ContentItem
    {
        $item = $this->itemWithSubmission($brand, $content);
        app(\App\Services\ContentItemService::class)->collect($item, $smm);

        return $item->fresh();
    }

    public function test_smm_can_publish_a_collected_submission(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->collectedItem($brand, $content, $smm);
        $submission = $item->latestSubmission();

        $response = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission->id, 'facebook_post_url' => 'https://facebook.com/post/1',
        ]);

        $response->assertOk();
        $this->assertSame(ContentItem::STATUS_PUBLISHED, $item->fresh()->status);
        $this->assertDatabaseHas('published_contents', [
            'content_item_id' => $item->id, 'submission_id' => $submission->id, 'facebook_post_url' => 'https://facebook.com/post/1',
        ]);
    }

    public function test_publishing_a_superseded_submission_is_refused(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->collectedItem($brand, $content, $smm);
        $staleSubmission = $item->latestSubmission();

        // A newer submission supersedes the collected one — e.g. content
        // re-submitted before the stale browser tab's publish click lands.
        app(\App\Services\ContentItemService::class)->submit($item, ['link_url' => 'https://example.com/v2.jpg'], $content);

        $response = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $staleSubmission->id, 'facebook_post_url' => 'https://facebook.com/post/1',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('published_contents', ['content_item_id' => $item->id]);
    }

    public function test_publishing_an_uncollected_submission_is_refused(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage published-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->itemWithSubmission($brand, $content);
        $submission = $item->latestSubmission();

        $response = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission->id, 'facebook_post_url' => 'https://facebook.com/post/1',
        ]);

        $response->assertStatus(422);
    }

    public function test_publishing_after_a_revision_was_requested_post_collection_is_refused(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->collectedItem($brand, $content, $smm);
        $submission = $item->latestSubmission();

        // Someone spots a problem after it was collected and sends it back —
        // that collected submission must not still be publishable.
        app(\App\Services\ContentItemService::class)->requestRevision($item, ['note' => 'Wrong crop'], $smm);

        $response = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission->id, 'facebook_post_url' => 'https://facebook.com/post/1',
        ]);

        $response->assertStatus(422);
    }

    public function test_publishing_twice_after_a_revision_produces_two_published_rows(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->collectedItem($brand, $content, $smm);
        $firstSubmission = $item->latestSubmission();

        $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $firstSubmission->id, 'facebook_post_url' => 'https://facebook.com/post/1',
        ])->assertOk();

        // Revise, resubmit, collect and publish again.
        app(\App\Services\ContentItemService::class)->requestRevision($item, [], $smm);
        app(\App\Services\ContentItemService::class)->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);
        $secondSubmission = $item->fresh()->latestSubmission();
        app(\App\Services\ContentItemService::class)->collect($item->fresh(), $smm);

        $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item->fresh()]), [
            'submission_id' => $secondSubmission->id, 'facebook_post_url' => 'https://facebook.com/post/2',
        ])->assertOk();

        $this->assertSame(2, \App\Models\PublishedContent::where('content_item_id', $item->id)->count());
        $this->assertDatabaseHas('published_contents', ['content_item_id' => $item->id, 'facebook_post_url' => 'https://facebook.com/post/1']);
        $this->assertDatabaseHas('published_contents', ['content_item_id' => $item->id, 'facebook_post_url' => 'https://facebook.com/post/2']);
    }

    // ── Revision notification ───────────────────────────────────────────

    public function test_requesting_a_revision_notifies_the_submitter_but_not_the_requester_themselves(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);
        $item = $this->itemWithSubmission($brand, $content);

        app(\App\Services\ContentItemService::class)->requestRevision($item, ['note' => 'Fix it'], $smm);

        Notification::assertSentTo($content, ChecklistRevisionRequested::class);
        Notification::assertNotSentTo($smm, ChecklistRevisionRequested::class);
    }

    public function test_a_self_requested_revision_does_not_notify_yourself(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->itemWithSubmission($brand, $content);

        app(\App\Services\ContentItemService::class)->requestRevision($item, [], $content);

        Notification::assertNothingSent();
    }
}
