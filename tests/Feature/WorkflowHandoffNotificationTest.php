<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItemSubmission;
use App\Models\ContentItemSubmissionApproval;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Notifications\ChecklistRevisionRequested;
use App\Notifications\ContentApprovedForPublishing;
use App\Notifications\ContentPublishedAndReviewed;
use App\Notifications\ContentReadyForPrePublishCheck;
use App\Notifications\ContentReadyForPublishingReview;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Workflow handoff notifications for the canonical Brand Content &
 * Advertising flow: Content/Design -> Marketing Pre-Publish Check -> SMM ->
 * Marketing Post-Publish Review -> Complete. Revision-requested
 * notifications (ChecklistRevisionRequested) are shared by both Marketing's
 * pre-publish revision and SMM's post-approval revision — same method, same
 * notification class, covered here as regression.
 */
class WorkflowHandoffNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'manage payments', 'view brand-checklist-overview',
            'view raw-content-panel', 'manage raw-content',
            'view designer-panel', 'manage designer-content',
            'view smm-panel', 'manage smm-collection', 'manage published-content',
            'manage publishing-review',
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

    private function service(): ContentItemService
    {
        return app(ContentItemService::class);
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF'.uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    private function adInvoice(Client $client, Brand $brand, User $manager, float $amount = 1000): Invoice
    {
        return Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => $amount, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
    }

    private function pay(Invoice $invoice, float $amount, User $recorder): Payment
    {
        $payment = Payment::create([
            'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'payment_category_id' => $invoice->payment_category_id,
            'amount' => $amount, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $recorder->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($invoice->fresh());

        return $payment;
    }

    private function readyBrand(User $manager): Brand
    {
        $client = $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);
        $this->pay($this->adInvoice($client, $brand, $manager, 1000), 1000, $manager);
        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 300, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    /** Submit -> Marketing approve -> SMM collect -> publish -> (optionally) review a brand-new item, end to end. */
    private function carryToPublished(Brand $brand, User $content, User $marketing, User $smm, string $category = 'raw_content'): array
    {
        $item = $this->service()->create($brand, ['category' => $category, 'title' => 'Item '.uniqid()], $content);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->service()->approveForHandover($item->fresh(), $submission->fresh(), $marketing);
        $this->service()->collect($item->fresh(), $smm);
        $published = $this->service()->publish($item->fresh(), $brand, $submission->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);

        return ['item' => $item->fresh(), 'submission' => $submission->fresh(), 'published' => $published];
    }

    // ── 1/2: fresh submission notifies Marketing, never SMM directly ────────

    public function test_raw_content_submission_notifies_marketing_not_smm(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        Notification::assertSentTo($marketing, ContentReadyForPrePublishCheck::class);
        Notification::assertNotSentTo($smm, ContentReadyForPrePublishCheck::class);
        Notification::assertNotSentTo($manager, ContentReadyForPrePublishCheck::class);
    }

    public function test_advertising_content_submission_notifies_marketing_not_smm(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'advertising_content', 'title' => 'Ad'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        Notification::assertSentTo($marketing, ContentReadyForPrePublishCheck::class);
        Notification::assertNotSentTo($smm, ContentReadyForPrePublishCheck::class);
    }

    public function test_poster_submission_notifies_marketing_not_smm(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments']);
        $design = $this->user('Design', ['manage designer-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'poster', 'title' => 'Poster'], $design);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $design);

        Notification::assertSentTo($marketing, ContentReadyForPrePublishCheck::class, function ($notification) {
            $data = $notification->toDatabase($notification);

            return $data['title'] === 'Poster Ready for Pre-Publish Check';
        });
        Notification::assertNotSentTo($smm, ContentReadyForPrePublishCheck::class);
    }

    // ── 3/4: revision-requested (pre-existing behavior, regression only) ───

    public function test_raw_content_revision_request_notifies_the_content_submitter(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo.'], $manager);

        Notification::assertSentTo($content, ChecklistRevisionRequested::class);
    }

    public function test_poster_revision_request_notifies_the_design_submitter(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $design = $this->user('Design', ['manage designer-content']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'poster', 'title' => 'Poster'], $design);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $design);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo.'], $manager);

        Notification::assertSentTo($design, ChecklistRevisionRequested::class);
    }

    // ── 5: resubmission after revision creates a brand-new Marketing notification, never SMM ─

    public function test_v2_resubmission_creates_a_new_marketing_notification_and_never_reaches_smm(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo.'], $manager);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);

        Notification::assertSentToTimes($marketing, ContentReadyForPrePublishCheck::class, 2);
        Notification::assertSentTo($marketing, ContentReadyForPrePublishCheck::class, function ($notification) {
            return str_contains($notification->toDatabase($notification)['message'], 'V2 of');
        });
        Notification::assertNotSentTo($smm, ContentReadyForPrePublishCheck::class);
        Notification::assertNotSentTo($smm, ContentApprovedForPublishing::class);
    }

    // ── 5b/6/15: Marketing approves exactly one version, notifies SMM, independently per version ─

    public function test_marketing_approval_notifies_smm_with_the_exact_version(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);
        $this->service()->approveForHandover($item->fresh(), $submission->fresh(), $marketing);

        Notification::assertSentTo($smm, ContentApprovedForPublishing::class, function ($notification) {
            $data = $notification->toDatabase($notification);

            return $data['title'] === 'Content Approved for Publishing' && str_contains($data['message'], 'V1 of');
        });
        Notification::assertNotSentTo($content, ContentApprovedForPublishing::class);
        Notification::assertNotSentTo($marketing, ContentApprovedForPublishing::class);
    }

    public function test_v1_approval_does_not_approve_v2_and_v2_gets_its_own_approval_notification(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $v1 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->service()->approveForHandover($item->fresh(), $v1->fresh(), $marketing);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo.'], $marketing);
        $v2 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);

        // V1's own approval does not carry over — V2 is not yet eligible.
        $this->assertFalse($v2->fresh()->approval()->exists());

        $this->service()->approveForHandover($item->fresh(), $v2->fresh(), $marketing);

        Notification::assertSentToTimes($smm, ContentApprovedForPublishing::class, 2);
        Notification::assertSentTo($smm, ContentApprovedForPublishing::class, function ($notification) {
            return str_contains($notification->toDatabase($notification)['message'], 'V2 of');
        });
    }

    // ── 7: duplicate approval is idempotent and never double-notifies ──────

    public function test_duplicate_approval_does_not_duplicate_the_smm_notification(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $this->user('Social Media Manager', ['manage smm-collection']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        $first = $this->service()->approveForHandover($item->fresh(), $submission->fresh(), $marketing);
        $second = $this->service()->approveForHandover($item->fresh(), $submission->fresh(), $marketing);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ContentItemSubmissionApproval::where('submission_id', $submission->id)->count());
        Notification::assertSentTimes(ContentApprovedForPublishing::class, 1);
    }

    // ── 8/9: publish notifies Marketing, never Manager ──────────────────────

    public function test_publish_notifies_marketing_but_not_manager(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);

        $this->carryToPublished($brand, $content, $marketing, $smm);

        Notification::assertSentTo($marketing, ContentReadyForPublishingReview::class);
        Notification::assertNotSentTo($manager, ContentPublishedAndReviewed::class);
    }

    // ── 10/11: Marketing final review notifies Manager, with the exact version ──

    public function test_marketing_review_notifies_manager_with_the_exact_version(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);

        ['published' => $published] = $this->carryToPublished($brand, $content, $marketing, $smm);

        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published]))->assertOk();

        $notification = $manager->notifications()->where('type', ContentPublishedAndReviewed::class)->first();
        $this->assertNotNull($notification);
        $this->assertSame('Content Published & Reviewed', $notification->data['title']);
        $this->assertStringContainsString('V1 of', $notification->data['message']);
    }

    // ── 12: a stale V1 review can never falsely complete V2 ─────────────────

    public function test_reviewing_a_superseded_v1_publication_never_notifies_manager_about_v2(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);

        ['item' => $item, 'published' => $v1Published] = $this->carryToPublished($brand, $content, $marketing, $smm);

        // V2 submitted directly (no revision request against V1 — this is
        // the one path that leaves V1 still formally reviewable: nothing
        // ever marked its publication revision_requested).
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);

        $manager->notifications()->delete();

        // V1's own publication can still legally be reviewed (no revision
        // was ever requested against it) — but it is no longer the item's
        // latest submission, so this must not claim V2's cycle is complete.
        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $v1Published]))->assertOk();

        $this->assertSame(0, $manager->notifications()->where('type', ContentPublishedAndReviewed::class)->count());
    }

    // ── 13: V2's own review creates its own, independent completion notification ─

    public function test_v2_review_creates_its_own_manager_completion_notification(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);

        ['item' => $item, 'submission' => $submission1, 'published' => $published1] =
            $this->carryToPublished($brand, $content, $marketing, $smm);

        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published1]))->assertOk();
        $this->assertSame(1, $manager->notifications()->where('type', ContentPublishedAndReviewed::class)->count());

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();
        // Timestamp columns are second-precision (see PublishedContent::
        // isRevisionRequested()'s own docblock) — without this, the revision
        // above and V2's publish below can land in the same wall-clock
        // second and the tie-break would wrongly pull this revision into
        // V2's own review window.
        $this->travel(1)->seconds();
        $v2 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);
        $submission2 = ContentItemSubmission::where('content_item_id', $item->id)->where('id', '!=', $submission1->id)->first();
        $this->service()->approveForHandover($item->fresh(), $submission2->fresh(), $marketing);
        $this->service()->collect($item->fresh(), $smm);
        $published2 = $this->service()->publish($item->fresh(), $brand, $submission2->fresh(), ['facebook_post_url' => 'https://facebook.com/v2'], $smm);

        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published2]))->assertOk();

        $this->assertSame(2, $manager->notifications()->where('type', ContentPublishedAndReviewed::class)->count());
        $latest = $manager->notifications()->where('type', ContentPublishedAndReviewed::class)->latest('id')->first();
        $this->assertStringContainsString('V2 of', $latest->data['message']);
    }

    // ── 14: a duplicate/retried review never duplicates the notification ────

    public function test_a_duplicate_review_request_does_not_duplicate_the_manager_notification(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);

        ['published' => $published] = $this->carryToPublished($brand, $content, $marketing, $smm);

        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published]))->assertOk();
        // Retried/double-submitted request against the same, now-reviewed row.
        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published]))->assertOk();

        $this->assertSame(1, $manager->notifications()->where('type', ContentPublishedAndReviewed::class)->count());
    }

    // ── a hold-blocked operation creates no handoff notification ────────────

    public function test_a_submission_blocked_by_hold_creates_no_handoff_notification(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);

        $brand->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Overdue invoice']);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);
        } finally {
            Notification::assertNothingSent();
        }
    }

    public function test_a_hold_blocked_approval_creates_no_handoff_notification(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        $brand->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Overdue invoice']);

        Notification::fake();
        $this->expectException(ValidationException::class);

        try {
            $this->service()->approveForHandover($item->fresh(), $submission->fresh(), $marketing);
        } finally {
            Notification::assertNothingSent();
            $this->assertNull($submission->fresh()->approval);
        }
    }

    // ── unrelated roles are never notified ──────────────────────────────────

    public function test_unrelated_roles_are_not_notified_by_submission_approval_or_publish(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $design = $this->user('Design', ['manage designer-content']);
        $brand = $this->readyBrand($manager);

        $this->carryToPublished($brand, $content, $marketing, $smm);

        Notification::assertNotSentTo($manager, ContentReadyForPrePublishCheck::class);
        Notification::assertNotSentTo($design, ContentReadyForPrePublishCheck::class);
        Notification::assertNotSentTo($smm, ContentReadyForPrePublishCheck::class);
        Notification::assertNotSentTo($content, ContentApprovedForPublishing::class);
        Notification::assertNotSentTo($marketing, ContentApprovedForPublishing::class);
        Notification::assertNotSentTo($content, ContentReadyForPublishingReview::class);
        Notification::assertNotSentTo($smm, ContentReadyForPublishingReview::class);
    }

    // ── persists to the database even with Reverb disabled ──────────────────

    public function test_notification_persists_to_the_database_with_reverb_disabled(): void
    {
        // No Notification::fake() here — this proves the real 'database'
        // channel write, not just that a Notification object was built.
        // Forced off explicitly rather than assumed from the environment —
        // this project's local .env now has real Reverb credentials (see
        // the Reverb setup work), so the disabled path has to be simulated
        // to keep this test deterministic regardless of that.
        config(['broadcasting.connections.reverb.key' => '', 'broadcasting.connections.reverb.options.host' => '']);

        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        $this->assertSame(
            1,
            $marketing->notifications()->where('type', ContentReadyForPrePublishCheck::class)->count()
        );
    }

    // ── notification links stay behind their own existing authorization ─────

    public function test_notification_links_remain_authorization_protected(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        $notification = $marketing->notifications()->where('type', ContentReadyForPrePublishCheck::class)->first();
        $this->assertSame(route('panels.marketing'), $notification->data['url']);
        // The link itself is unaffected by this feature — still just the
        // existing panel route, still gated by its own existing middleware.
        $this->get($notification->data['url'])->assertRedirect(route('login'));
    }

    // ── Realtime integration: same channel contract as Task, delivered instantly ──

    public function test_new_workflow_notifications_use_the_same_channel_contract_as_task_plus_instant_delivery(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        $notification = new ContentReadyForPrePublishCheck($item->fresh(), $submission, 1, $content);

        // Exactly the channel set every already-working dashboard notification
        // (e.g. TaskAssigned) uses — 'database' as the source of truth,
        // 'broadcast' for the live bell/sound/desktop alert.
        $this->assertSame(['database', 'broadcast'], $notification->via($marketing));

        $broadcast = $notification->toBroadcast($marketing);
        // Same payload shape the database row stores — no separate contract.
        $this->assertSame($notification->toDatabase($marketing), $broadcast->data);
        // The one deliberate difference from a plain BroadcastsToDashboard
        // notification: delivery doesn't wait on a queue worker.
        $this->assertSame('sync', $broadcast->connection);
    }

    public function test_revision_notification_also_uses_instant_broadcast_delivery(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);
        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);

        $notification = new ChecklistRevisionRequested($item->fresh(), $manager, 'Redo.');

        $this->assertSame(['database', 'broadcast'], $notification->via($content));
        $this->assertSame('sync', $notification->toBroadcast($content)->connection);
    }

    public function test_a_broadcast_delivery_failure_does_not_break_the_underlying_submission(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $this->user('Marketing', ['manage publishing-review']); // must exist to be resolved as a recipient
        $brand = $this->readyBrand($manager);
        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Raw'], $content);

        // Simulates exactly what forcing the broadcast onto the "sync"
        // connection risks: Reverb unreachable, surfacing as an exception in
        // this same request instead of an isolated queued-job failure.
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Reverb unreachable'));

        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        // The submission already committed before the notification was ever
        // attempted — notifySafely() must have caught and swallowed the
        // exception above rather than letting it bubble out of submit().
        $this->assertDatabaseHas('content_item_submissions', ['id' => $submission->id]);
    }
}
