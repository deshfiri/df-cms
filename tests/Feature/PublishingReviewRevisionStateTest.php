<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentItemRevision;
use App\Models\ContentItemSubmission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\PublishedContent;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Manual QA found a real bug: once Marketing requests revision on a
 * publication, the ContentItem correctly becomes needs_revision — but the
 * OLD PublishedContent row (still reviewed_at = null) kept showing as a
 * normal, actionable "awaiting review" row in Marketing Publishing Review
 * and Manager Oversight, could still be Mark Reviewed, and Request Revision
 * itself could be clicked again, piling up duplicate ContentItemRevision
 * rows (reproduced live: revision id 6 previous_status=published, id 7
 * previous_status=needs_revision, same item, 82 seconds apart).
 *
 * Fixed via PublishedContent::annotateReviewStates() — the one place a
 * publication's derived review_state (reviewed / revision_requested /
 * awaiting_review) is computed, bounded to each publication's own window
 * between its own published_at and whatever published after it (if
 * anything) — so a revision against V1 marks V1 forever, never leaks onto
 * a later V2, and V2 never inherits V1's revision.
 */
class PublishingReviewRevisionStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'manage payments', 'manage raw-content', 'manage designer-content',
            'manage smm-collection', 'manage published-content', 'manage publishing-review',
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

    /** Create -> submit -> collect -> publish, returning everything a test might need next. */
    private function publishedItem(Brand $brand, User $content, User $smm, string $category = 'raw_content'): array
    {
        $service = app(ContentItemService::class);
        $item = $service->create($brand, ['category' => $category, 'title' => 'Item '.uniqid()], $content);
        $submission = $service->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $collection = $service->collect($item->fresh(), $smm);
        $published = $service->publish($item->fresh(), $brand, $submission->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);

        return compact('item', 'submission', 'collection', 'published');
    }

    private function unreviewedIds($marketing, Brand $brand): array
    {
        $response = $this->actingAs($marketing)->getJson(route('marketing.published-contents.unreviewed', $brand));

        return collect($response->json('data'))->pluck('id')->all();
    }

    // ── 1. Publish V1 appears in Marketing Publishing Review ───────────────

    public function test_1_publish_v1_appears_in_marketing_publishing_review(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['published' => $published] = $this->publishedItem($brand, $content, $smm);

        $this->assertContains($published->id, $this->unreviewedIds($marketing, $brand));
    }

    // ── 2. Requesting revision on V1 removes it from the actionable queue ──

    public function test_2_requesting_revision_on_v1_removes_it_from_the_actionable_queue_but_keeps_history(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'published' => $published] = $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]),
            ['note' => 'Wrong crop, please redo.']
        )->assertOk();

        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
        $this->assertDatabaseHas('content_item_revisions', ['content_item_id' => $item->id]);

        // History preserved — the row itself still exists, untouched.
        $this->assertDatabaseHas('published_contents', [
            'id' => $published->id, 'submission_id' => $published->submission_id, 'reviewed_at' => null,
        ]);

        // But it must no longer be an actionable "awaiting review" item.
        $this->assertNotContains($published->id, $this->unreviewedIds($marketing, $brand));
    }

    // ── 3. Duplicate revision request while needs_revision is rejected ─────

    public function test_3_duplicate_revision_request_while_needs_revision_is_rejected(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item] = $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'First.']
        )->assertOk();
        $this->assertCount(1, ContentItemRevision::where('content_item_id', $item->id)->get());

        $second = $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Second — should be refused.']
        );

        $second->assertStatus(422);
        $this->assertCount(1, ContentItemRevision::where('content_item_id', $item->id)->get(), 'No additional revision row should be created.');
        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
    }

    // ── 4. Mark Reviewed on V1 after revision requested is rejected ────────

    public function test_4_mark_reviewed_on_v1_after_revision_requested_is_rejected(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'published' => $published] = $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();

        $response = $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published]));

        $response->assertStatus(422);
        $published->refresh();
        $this->assertNull($published->reviewed_at);
        $this->assertNull($published->reviewed_by);
    }

    // ── 5. SMM Published shows "revision_requested", not "awaiting_review" ─

    public function test_5_smm_published_shows_revision_requested_state(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'published' => $published] = $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();

        $response = $this->actingAs($smm)->getJson(route('panels.smm.published'));
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $published->id);
        $this->assertNotNull($row, 'The historical publication must still be visible.');
        $this->assertSame('revision_requested', $row['review_state']);
    }

    // ── 6. Full revised lifecycle: V1 stays historical, V2 is fresh ────────

    public function test_6_full_revised_lifecycle_v1_stays_historical_v2_is_fresh(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'submission' => $submission1, 'published' => $published1] =
            $this->publishedItem($brand, $content, $smm);

        // Request revision on V1.
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();

        // Each real lifecycle step is a separate human action, always
        // seconds or minutes apart in reality — travel forward between them
        // so the review-state window logic is exercised at realistic
        // timing, not accidentally tied to the same stored second.
        $this->travel(1)->seconds();

        // Resubmit -> available, NOT auto-collected.
        $this->actingAs($content)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/v2.jpg',
        ])->assertOk();
        $item->refresh();
        $this->assertSame(ContentItem::STATUS_AVAILABLE, $item->status);

        $submission2Id = ContentItemSubmission::where('content_item_id', $item->id)
            ->where('id', '!=', $submission1->id)->value('id');
        $this->assertNotNull($submission2Id);

        $this->travel(1)->seconds();

        // Collect V2.
        $this->actingAs($smm)->postJson(route('marketing.content-items.collect', [$brand, $item]))->assertOk();

        $this->travel(1)->seconds();

        // Publish V2.
        $publish2 = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission2Id, 'facebook_post_url' => 'https://facebook.com/v2',
        ]);
        $publish2->assertOk();
        $published2Id = $publish2->json('data.id');
        $this->assertNotEquals($published1->id, $published2Id);

        // V1 still exists, unchanged, and remains revision-requested forever.
        $this->assertDatabaseHas('published_contents', [
            'id' => $published1->id, 'submission_id' => $submission1->id, 'reviewed_at' => null,
        ]);
        $smmRows = collect($this->actingAs($smm)->getJson(route('panels.smm.published'))->json('data'));
        $this->assertSame('revision_requested', $smmRows->firstWhere('id', $published1->id)['review_state']);

        // V2 is a fresh, separate, actionable publication.
        $ids = $this->unreviewedIds($marketing, $brand);
        $this->assertContains($published2Id, $ids);
        $this->assertNotContains($published1->id, $ids);
        $this->assertSame('awaiting_review', $smmRows->firstWhere('id', $published2Id)['review_state']);

        // V2 can be marked reviewed.
        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published2Id]))->assertOk();

        $v2 = PublishedContent::find($published2Id);
        $this->assertNotNull($v2->reviewed_at);

        // Reviewing V2 never touches V1's history.
        $published1->refresh();
        $this->assertNull($published1->reviewed_at);
    }

    // ── 7. After V2 exists, requesting revision on V2 starts a new cycle ───

    public function test_7_requesting_revision_on_v2_starts_a_new_cycle(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'submission' => $submission1] = $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();
        $this->travel(1)->seconds();
        $this->actingAs($content)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/v2.jpg',
        ])->assertOk();
        $submission2Id = ContentItemSubmission::where('content_item_id', $item->id)->where('id', '!=', $submission1->id)->value('id');
        $this->travel(1)->seconds();
        $this->actingAs($smm)->postJson(route('marketing.content-items.collect', [$brand, $item]))->assertOk();
        $this->travel(1)->seconds();
        $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission2Id, 'facebook_post_url' => 'https://facebook.com/v2',
        ])->assertOk();

        $this->assertSame(ContentItem::STATUS_PUBLISHED, $item->fresh()->status);

        // A fresh revision request on V2's own cycle must be allowed.
        $response = $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'V2 also needs a fix.']
        );
        $response->assertOk();
        $this->assertCount(2, ContentItemRevision::where('content_item_id', $item->id)->get());
        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
    }

    // ── 8. On-hold guard is unchanged ───────────────────────────────────────

    public function test_8_revision_request_still_blocked_while_checklist_on_hold(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item] = $this->publishedItem($brand, $content, $smm);

        $item->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Payment reversed.']);

        $response = $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        );

        $response->assertStatus(422);
        $this->assertSame(ContentItem::STATUS_PUBLISHED, $item->fresh()->status);
    }

    // ── 9. Authorization unchanged ──────────────────────────────────────────

    public function test_9_content_and_design_still_cannot_request_revision(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $design = $this->user('Design', ['manage designer-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);
        ['item' => $item] = $this->publishedItem($brand, $content, $smm);

        $this->actingAs($content)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'nope']
        )->assertForbidden();

        $this->actingAs($design)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'nope']
        )->assertForbidden();
    }

    public function test_9b_marketing_smm_and_manager_can_still_request_and_review(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'published' => $published] = $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published]))->assertOk();
        $this->assertNotNull($published->fresh()->reviewed_at);
    }

    // ── Manager Oversight also stops offering the stale row ────────────────

    public function test_manager_oversight_also_excludes_a_revision_requested_publication(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'published' => $published] = $this->publishedItem($brand, $content, $smm);

        $page = $this->actingAs($manager)->get(route('manager.oversight'));
        $page->assertViewHas('unreviewed', fn ($list) => $list->firstWhere('id', $published->id) !== null);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();

        $page = $this->actingAs($manager)->get(route('manager.oversight'));
        $page->assertViewHas('unreviewed', fn ($list) => $list->firstWhere('id', $published->id) === null);
    }
}
