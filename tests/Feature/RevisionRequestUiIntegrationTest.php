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
use App\Models\PublishedContent;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UI integration for "Request Revision" in Marketing Publishing Review, SMM
 * Published, and Manager Oversight — all three reuse the existing
 * marketing.content-items.request-revision route/controller/service
 * unchanged. See ContentItemService::requestRevision() (status-agnostic by
 * design) and ContentItemController::authorizeReviewer() (SMM/Marketing/
 * Manager, unchanged here).
 */
class RevisionRequestUiIntegrationTest extends TestCase
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

    /** Create -> submit -> Marketing approve -> collect -> publish, returning everything a test might need next. */
    private function publishedItem(Brand $brand, User $content, User $smm, string $category = 'raw_content'): array
    {
        $service = app(ContentItemService::class);
        $item = $service->create($brand, ['category' => $category, 'title' => 'Item '.uniqid()], $content);
        $submission = $service->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $service->approveForHandover($item->fresh(), $submission->fresh(), $marketing);
        $collection = $service->collect($item->fresh(), $smm);
        $published = $service->publish($item->fresh(), $brand, $submission->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);

        return compact('item', 'submission', 'collection', 'published');
    }

    // ── A. Marketing requests revision from an unreviewed published item ──

    public function test_a_marketing_requests_revision_from_an_unreviewed_published_item(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'published' => $published] = $this->publishedItem($brand, $content, $smm);

        $this->assertNull($published->reviewed_at);

        $response = $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]),
            ['note' => 'Wrong crop, please redo.']
        );

        $response->assertOk();
        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
        $this->assertDatabaseHas('content_item_revisions', [
            'content_item_id' => $item->id, 'requested_by' => $marketing->id, 'note' => 'Wrong crop, please redo.',
        ]);
        // Still untouched — revision never marks it reviewed.
        $this->assertNull($published->fresh()->reviewed_at);
    }

    // ── B. Marketing requests revision AFTER review; review history untouched ──

    public function test_b_marketing_requests_revision_after_review_and_the_review_stays_untouched(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'submission' => $submission1, 'collection' => $collection1, 'published' => $published1] =
            $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published1]))->assertOk();
        $published1->refresh();
        $reviewedAt = $published1->reviewed_at;
        $reviewedBy = $published1->reviewed_by;
        $this->assertNotNull($reviewedAt);

        $response = $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]),
            ['note' => 'Actually needs a different image.']
        );
        $response->assertOk();

        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
        // Historical integrity (requirement 5): nothing about the old publish,
        // its review, its submission, or its collection moved.
        $published1->refresh();
        $this->assertEquals($reviewedAt->timestamp, $published1->reviewed_at->timestamp);
        $this->assertSame($reviewedBy, $published1->reviewed_by);
        $this->assertSame('https://facebook.com/v1', $published1->facebook_post_url);
        $this->assertDatabaseHas('content_item_submissions', ['id' => $submission1->id, 'link_url' => 'https://example.com/v1.jpg']);
        $this->assertDatabaseHas('content_item_collections', ['id' => $collection1->id, 'submission_id' => $submission1->id]);
    }

    // ── C. SMM requests revision from Published ──────────────────────────

    public function test_c_smm_requests_revision_from_published(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);
        ['item' => $item] = $this->publishedItem($brand, $content, $smm);

        $response = $this->actingAs($smm)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]),
            ['note' => 'SMM caught a typo after publishing.']
        );

        $response->assertOk();
        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
    }

    // ── D. Manager requests revision through the appropriate UI/HTTP flow ──

    public function test_d_manager_requests_revision_and_sees_it_on_the_oversight_page(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'published' => $published] = $this->publishedItem($brand, $content, $smm);

        // The oversight page surfaces the unreviewed publish for the Manager to act on.
        $page = $this->actingAs($manager)->get(route('manager.oversight'));
        $page->assertOk();
        $page->assertViewHas('unreviewed', fn ($list) => $list->firstWhere('id', $published->id) !== null);
        $page->assertSee($item->title);

        $response = $this->actingAs($manager)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]),
            ['note' => 'Manager caught an issue during oversight.']
        );

        $response->assertOk();
        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
        $this->assertDatabaseHas('content_item_revisions', ['content_item_id' => $item->id, 'requested_by' => $manager->id]);
    }

    // ── E/F/G: full resubmission cycle after revision ──────────────────────

    public function test_e_f_g_revised_submission_returns_to_available_not_auto_collected_and_can_be_published_again(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'submission' => $submission1, 'collection' => $collection1, 'published' => $published1] =
            $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]),
            ['note' => 'Please redo.']
        )->assertOk();

        // Resubmit (owner resubmits) — E: lands back in `available`, not collected.
        $resubmit = $this->actingAs($content)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/v2.jpg',
        ]);
        $resubmit->assertOk();
        $item->refresh();
        $this->assertSame(ContentItem::STATUS_AVAILABLE, $item->status);

        $submission2Id = ContentItemSubmission::where('content_item_id', $item->id)
            ->where('id', '!=', $submission1->id)->value('id');
        $this->assertNotNull($submission2Id);

        // F: the OLD collection must not make this new submission look collected.
        $this->assertFalse(
            ContentItemCollection::where('submission_id', $submission2Id)->exists(),
            'The revised submission must not inherit the previous collection.'
        );
        $this->assertSame($submission1->id, $collection1->fresh()->submission_id, "The old collection row must still point at round 1's submission.");

        // G: it needs its own fresh Marketing approval + collect + publish
        // cycle, and that succeeds.
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.submissions.approve', [$brand, $item, $submission2Id])
        )->assertOk();
        $collectResponse = $this->actingAs($smm)->postJson(route('marketing.content-items.collect', [$brand, $item]));
        $collectResponse->assertOk();
        $this->assertSame(ContentItem::STATUS_COLLECTED, $item->fresh()->status);

        $publishResponse = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission2Id, 'facebook_post_url' => 'https://facebook.com/v2',
        ]);
        $publishResponse->assertOk();
        $published2Id = $publishResponse->json('data.id');
        $this->assertNotEquals($published1->id, $published2Id);

        // H (bridges into the next test too): new publish shows up for review,
        // old one is untouched.
        $this->assertDatabaseHas('published_contents', [
            'id' => $published1->id, 'submission_id' => $submission1->id, 'facebook_post_url' => 'https://facebook.com/v1',
        ]);
    }

    // ── H. New publication appears in review queue; old history intact ──────

    public function test_h_new_publication_appears_in_marketing_review_while_old_publication_remains_intact(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        ['item' => $item, 'submission' => $submission1, 'published' => $published1] =
            $this->publishedItem($brand, $content, $smm);

        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published1]))->assertOk();
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]),
            ['note' => 'Redo please.']
        )->assertOk();

        // Real lifecycle steps are always seconds-to-minutes apart — travel
        // forward so PublishedContent::annotateReviewStates()'s review-state
        // window isn't exercised at the same stored second this revision
        // was requested in (it compares against published_at, not id order).
        $this->travel(1)->seconds();

        $this->actingAs($content)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/v2.jpg',
        ])->assertOk();
        $submission2Id = ContentItemSubmission::where('content_item_id', $item->id)->where('id', '!=', $submission1->id)->value('id');
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.submissions.approve', [$brand, $item, $submission2Id])
        )->assertOk();
        $this->actingAs($smm)->postJson(route('marketing.content-items.collect', [$brand, $item]))->assertOk();
        $publish2 = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission2Id, 'facebook_post_url' => 'https://facebook.com/v2-final',
        ]);
        $publish2->assertOk();
        $published2Id = $publish2->json('data.id');

        $unreviewed = $this->actingAs($marketing)->getJson(route('marketing.published-contents.unreviewed', $brand));
        $ids = collect($unreviewed->json('data'))->pluck('id')->all();
        $this->assertContains($published2Id, $ids);
        $this->assertNotContains($published1->id, $ids, 'The old, already-reviewed publish must not reappear as unreviewed.');

        // Old publish row is still there, completely unchanged.
        $this->assertDatabaseHas('published_contents', [
            'id' => $published1->id, 'facebook_post_url' => 'https://facebook.com/v1',
        ]);
        $this->assertNotNull($published1->fresh()->reviewed_at);
    }

    // ── 6. Authorization — positive and negative ────────────────────────────

    public function test_authorization_content_and_design_cannot_request_revision(): void
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
}
