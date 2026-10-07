<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemCollection;
use App\Models\ContentItemSubmission;
use App\Models\ContentItemSubmissionApproval;
use App\Models\PublishedContent;
use App\Models\User;
use App\Notifications\ChecklistRevisionRequested;
use App\Notifications\ContentApprovedForPublishing;
use App\Notifications\ContentPublishedAndReviewed;
use App\Notifications\ContentReadyForPrePublishCheck;
use App\Notifications\ContentReadyForPublishingReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * The Brand Content & Advertising workflow after the Marketing pre-publish
 * change:
 *
 *   Content / Design -> Marketing Pre-Publish Check -> SMM -> Marketing
 *   Post-Publish Review -> Complete (Manager notified)
 *
 * Every state transition below is asserted through the real service and HTTP
 * endpoints. Notification routing is covered in MarketingHandoffRoutingTest.
 */
class MarketingPrePublishWorkflowTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkflowPermissions();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function team(): array
    {
        return $this->workflowTeam();
    }

    private function ids(TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('id')->all();
    }

    private function pendingCheckIds(User $viewer): array
    {
        return $this->ids($this->actingAs($viewer)->getJson(route('panels.marketing.pending-check')));
    }

    private function smmAvailableIds(User $viewer): array
    {
        return $this->ids($this->actingAs($viewer)->getJson(route('panels.smm.available')));
    }

    /** Notifications keep their payload private; read it the same way the mailer/broadcaster would. */
    private function privateProperty(object $notification, string $name): mixed
    {
        return (fn () => $this->{$name})->call($notification);
    }

    private function approveUrl(Brand $brand, ContentItem $item, ContentItemSubmission $submission): string
    {
        return route('marketing.content-items.submissions.approve', [$brand, $item, $submission]);
    }

    // ── 1–3. Every category routes to Marketing first, never to SMM ───────────

    public function test_raw_content_submission_goes_to_marketing_pre_publish_check_not_smm(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);

        $item = $this->newItem($brand, $t['content'], ContentItem::CATEGORY_RAW_CONTENT);
        $this->submitVersion($item, $t['content']);

        $this->assertSame(ContentItem::STATUS_AVAILABLE, $item->fresh()->status);
        $this->assertNull($item->fresh()->latestSubmission()->approval);
        $this->assertContains($item->id, $this->pendingCheckIds($t['marketing']));
        $this->assertNotContains($item->id, $this->smmAvailableIds($t['smm']));
    }

    public function test_advertising_content_submission_goes_to_marketing_pre_publish_check_not_smm(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);

        $item = $this->newItem($brand, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $this->submitVersion($item, $t['content']);

        $this->assertContains($item->id, $this->pendingCheckIds($t['marketing']));
        $this->assertNotContains($item->id, $this->smmAvailableIds($t['smm']));
    }

    public function test_poster_submission_goes_to_marketing_pre_publish_check_not_smm(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);

        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submitVersion($item, $t['design']);

        $this->assertContains($item->id, $this->pendingCheckIds($t['marketing']));
        $this->assertNotContains($item->id, $this->smmAvailableIds($t['smm']));
    }

    // ── 4–7. SMM eligibility is exact-version and gated by Marketing approval ─

    public function test_smm_cannot_collect_a_submitted_version_before_marketing_approval(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);

        $this->expectException(ValidationException::class);
        $this->service()->collect($item->fresh(), $t['smm']);
    }

    public function test_smm_collect_endpoint_refuses_an_unapproved_version_with_422(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);

        $this->actingAs($t['smm'])
            ->postJson(route('marketing.content-items.collect', [$brand, $item]))
            ->assertStatus(422);

        $this->assertSame(0, ContentItemCollection::count());
    }

    public function test_marketing_can_approve_the_exact_v1_submission(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);

        $this->actingAs($t['marketing'])
            ->postJson($this->approveUrl($brand, $item, $v1))
            ->assertOk()
            ->assertJsonPath('data.submission_id', $v1->id);

        $approval = ContentItemSubmissionApproval::where('submission_id', $v1->id)->firstOrFail();
        $this->assertSame($t['marketing']->id, $approval->approved_by);
    }

    public function test_marketing_approval_makes_exactly_that_version_collectable_by_smm(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);

        $this->assertContains($item->id, $this->smmAvailableIds($t['smm']));

        $this->collect($item, $t['smm']);

        $collection = ContentItemCollection::where('content_item_id', $item->id)->firstOrFail();
        $this->assertSame($v1->id, $collection->submission_id);
        $this->assertSame(ContentItem::STATUS_COLLECTED, $item->fresh()->status);
    }

    public function test_marketing_approval_notifies_smm_with_the_exact_version(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);

        $this->approve($item, $v1, $t['marketing']);

        Notification::assertSentTo($t['smm'], ContentApprovedForPublishing::class,
            fn (ContentApprovedForPublishing $n) => $this->privateProperty($n, 'submission')->id === $v1->id);
    }

    // ── 8–11. Pre-publish revisions route back to the right maker, and block SMM ─

    public function test_marketing_pre_publish_revision_on_raw_content_routes_back_to_content(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content'], ContentItem::CATEGORY_RAW_CONTENT);
        $this->submitVersion($item, $t['content']);

        $this->service()->requestRevision($item->fresh(), ['note' => 'Fix the caption'], $t['marketing']);

        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
        Notification::assertSentTo($t['content'], ChecklistRevisionRequested::class);
        Notification::assertNotSentTo($t['smm'], ChecklistRevisionRequested::class);
    }

    public function test_marketing_pre_publish_revision_on_advertising_content_routes_back_to_content(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $this->submitVersion($item, $t['content']);

        $this->service()->requestRevision($item->fresh(), ['note' => 'Wrong offer'], $t['marketing']);

        $this->assertSame(ContentItem::STATUS_NEEDS_REVISION, $item->fresh()->status);
        Notification::assertSentTo($t['content'], ChecklistRevisionRequested::class);
    }

    public function test_marketing_pre_publish_revision_on_poster_routes_back_to_design(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submitVersion($item, $t['design']);

        $this->service()->requestRevision($item->fresh(), ['note' => 'Logo too small'], $t['marketing']);

        Notification::assertSentTo($t['design'], ChecklistRevisionRequested::class);
        Notification::assertNotSentTo($t['content'], ChecklistRevisionRequested::class);
    }

    public function test_a_revision_requested_v1_cannot_be_collected_or_approved(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo'], $t['marketing']);

        try {
            $this->service()->collect($item->fresh(), $t['smm']);
            $this->fail('SMM collected a revision-requested version.');
        } catch (ValidationException) {
            // expected
        }

        $this->expectException(ValidationException::class);
        $this->approve($item, $v1, $t['marketing']);
    }

    // ── 12–15. V2 always comes back through Marketing, and V1 approval never covers it ─

    public function test_after_v1_revision_the_v2_resubmission_goes_back_to_marketing(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo'], $t['marketing']);

        $v2 = $this->submitVersion($item, $t['content']);

        $this->assertSame(ContentItem::STATUS_AVAILABLE, $item->fresh()->status);
        $this->assertSame($v2->id, $item->fresh()->latestSubmission()->id);
        $this->assertContains($item->id, $this->pendingCheckIds($t['marketing']));
        Notification::assertSentToTimes($t['marketing'], ContentReadyForPrePublishCheck::class, 2);
    }

    public function test_v2_resubmission_does_not_reach_smm_before_marketing_approval(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->submitVersion($item, $t['content']);

        Notification::assertNothingSentTo($t['smm']);
        $this->assertNotContains($item->id, $this->smmAvailableIds($t['smm']));
        $this->expectException(ValidationException::class);
        $this->service()->collect($item->fresh(), $t['smm']);
    }

    public function test_v1_approval_does_not_approve_v2(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $this->service()->requestRevision($item->fresh(), ['note' => 'SMM found an issue'], $t['smm']);

        $v2 = $this->submitVersion($item, $t['content']);

        $this->assertSame(0, ContentItemSubmissionApproval::where('submission_id', $v2->id)->count());
        $this->assertSame(1, ContentItemSubmissionApproval::where('submission_id', $v1->id)->count());
        $this->expectException(ValidationException::class);
        $this->service()->collect($item->fresh(), $t['smm']);
    }

    public function test_marketing_approving_the_exact_v2_makes_v2_eligible_for_smm(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo'], $t['smm']);
        $v2 = $this->submitVersion($item, $t['content']);

        $this->approve($item, $v2, $t['marketing']);
        $this->assertContains($item->id, $this->smmAvailableIds($t['smm']));
        $this->collect($item, $t['smm']);

        $this->assertSame($v2->id, ContentItemCollection::where('content_item_id', $item->id)->latest('id')->firstOrFail()->submission_id);
    }

    // ── 16–21. Post-publish final review, and what counts as a completed cycle ─

    public function test_smm_publication_sends_the_item_back_to_marketing_for_final_review(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);

        $published = $this->publish($item, $brand, $v1, $t['smm']);

        $this->assertSame(ContentItem::STATUS_PUBLISHED, $item->fresh()->status);
        $this->assertNull($published->fresh()->reviewed_at);
        Notification::assertSentTo($t['marketing'], ContentReadyForPublishingReview::class);
    }

    public function test_smm_publication_alone_does_not_notify_manager_or_complete_the_cycle(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $this->publish($item, $brand, $v1, $t['smm']);

        Notification::assertNothingSentTo($t['manager']);
        $this->assertSame(0, PublishedContent::query()->whereNotNull('reviewed_at')->count());
    }

    public function test_marketing_final_review_completes_the_publication_cycle(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $published = $this->publish($item, $brand, $v1, $t['smm']);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.published-contents.review', [$brand, $published]))
            ->assertOk();

        $this->assertNotNull($published->fresh()->reviewed_at);
        Notification::assertSentTo($t['manager'], ContentPublishedAndReviewed::class);
    }

    public function test_final_review_of_the_latest_cycle_notifies_manager_about_that_version(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $this->publish($item, $brand, $v1, $t['smm']);
        // Each step happens a little later, as it would in real use. Revision
        // timestamps are second-precision, so steps in the same second would
        // look like they belong to the same publication.
        $this->travel(1)->minutes();
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->travel(1)->minutes();
        $v2 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v2, $t['marketing']);
        $this->collect($item, $t['smm']);
        $this->travel(1)->minutes();
        $published2 = $this->publish($item, $brand, $v2, $t['smm']);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.published-contents.review', [$brand, $published2]))
            ->assertOk();

        Notification::assertSentTo($t['manager'], ContentPublishedAndReviewed::class,
            fn (ContentPublishedAndReviewed $n) => $this->privateProperty($n, 'published')->id === $published2->id);
    }

    public function test_a_stale_v1_review_does_not_falsely_complete_a_newer_v2_cycle(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $published1 = $this->publish($item, $brand, $v1, $t['smm']);

        // V2 arrives before Marketing gets round to reviewing V1.
        $this->submitVersion($item, $t['content']);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.published-contents.review', [$brand, $published1]))
            ->assertOk();

        Notification::assertNotSentTo($t['manager'], ContentPublishedAndReviewed::class);
        $this->assertSame(ContentItem::STATUS_AVAILABLE, $item->fresh()->status);
        $this->assertFalse(PublishedContent::where('submission_id', $item->fresh()->latestSubmission()->id)->exists());
    }

    // ── 22–25. Hold, brand isolation, idempotency and concurrency ─────────────

    public function test_a_checklist_hold_blocks_marketing_pre_publish_approval(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $brand->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Ad budget refunded']);

        $this->actingAs($t['marketing'])
            ->postJson($this->approveUrl($brand, $item, $v1))
            ->assertStatus(422);

        $this->assertSame(0, ContentItemSubmissionApproval::count());
    }

    public function test_marketing_approval_is_refused_across_brands(): void
    {
        $t = $this->team();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $item = $this->newItem($brandA, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);

        $this->actingAs($t['marketing'])
            ->postJson($this->approveUrl($brandB, $item, $v1))
            ->assertNotFound();

        $this->assertSame(0, ContentItemSubmissionApproval::count());
    }

    public function test_a_submission_from_another_item_cannot_be_approved_through_this_item(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $itemA = $this->newItem($brand, $t['content']);
        $itemB = $this->newItem($brand, $t['content']);
        $subB = $this->submitVersion($itemB, $t['content']);

        $this->actingAs($t['marketing'])
            ->postJson($this->approveUrl($brand, $itemA, $subB))
            ->assertNotFound();
    }

    public function test_duplicate_approval_is_idempotent_and_notifies_smm_once(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);

        $this->actingAs($t['marketing'])->postJson($this->approveUrl($brand, $item, $v1))->assertOk();
        $this->actingAs($t['marketing'])->postJson($this->approveUrl($brand, $item, $v1))->assertOk();

        $this->assertSame(1, ContentItemSubmissionApproval::where('submission_id', $v1->id)->count());
        Notification::assertSentToTimes($t['smm'], ContentApprovedForPublishing::class, 1);
    }

    public function test_a_stale_approval_for_an_already_approved_version_is_a_safe_no_op(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);

        // Two Marketing users both loaded the item before either approved it.
        $staleA = $item->fresh();
        $staleB = $item->fresh();
        $first = $this->service()->approveForHandover($staleA, $v1->fresh(), $t['marketing']);
        $second = $this->service()->approveForHandover($staleB, $v1->fresh(), $t['marketing']);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ContentItemSubmissionApproval::count());
    }

    public function test_a_stale_approval_for_a_superseded_version_is_refused(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $staleCopy = $item->fresh();
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->submitVersion($item, $t['content']);

        $this->expectException(ValidationException::class);
        $this->service()->approveForHandover($staleCopy, $v1->fresh(), $t['marketing']);
    }

    // ── 26. SMM-requested revision never bypasses Marketing ───────────────────

    public function test_smm_revision_after_approval_goes_to_content_and_resubmission_returns_to_marketing(): void
    {
        Notification::fake();
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);

        $this->service()->requestRevision($item->fresh(), ['note' => 'Image is cropped'], $t['smm']);
        Notification::assertSentTo($t['content'], ChecklistRevisionRequested::class);

        $v2 = $this->submitVersion($item, $t['content']);

        Notification::assertSentToTimes($t['marketing'], ContentReadyForPrePublishCheck::class, 2);
        Notification::assertNotSentTo($t['smm'], ContentReadyForPrePublishCheck::class);
        $this->assertNotContains($item->id, $this->smmAvailableIds($t['smm']));
        $this->expectException(ValidationException::class);
        $this->service()->collect($item->fresh(), $t['smm']);
    }

    // ── 27–29. Checklist representation, history, and backward compatibility ──

    public function test_the_shared_checklist_shows_the_marketing_pre_publish_stage(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);

        $this->actingAs($t['manager'])->get(route('marketing.checklist', $brand))
            ->assertOk()->assertSee('Pending Marketing Check');

        $this->approve($item, $v1, $t['marketing']);
        $this->actingAs($t['manager'])->get(route('marketing.checklist', $brand))
            ->assertOk()->assertSee('Marketing Approved — Ready for SMM');

        $this->collect($item, $t['smm']);
        $this->actingAs($t['manager'])->get(route('marketing.checklist', $brand))
            ->assertOk()->assertSee('Collected');
    }

    public function test_version_history_keeps_each_versions_marketing_approval_metadata(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Redo'], $t['smm']);
        $v2 = $this->submitVersion($item, $t['content']);

        $approval = ContentItemSubmissionApproval::where('submission_id', $v1->id)->firstOrFail();
        $this->assertSame($t['marketing']->id, $approval->approved_by);
        $this->assertNotNull($approval->approved_at);
        $this->assertNull($v2->fresh()->approval);
        $this->assertSame(1, $item->fresh()->submissionApprovals()->count());
        $this->assertSame(1, ActivityLog::where('action', 'Marketing Approved')->count());
    }

    public function test_legacy_published_records_without_an_approval_stay_readable_and_reviewable(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $legacy = $this->submitVersion($item, $t['content']);

        // A cycle completed before the approval table existed: no approval row,
        // but the collection and publication rows exist as they always did.
        // The policy is no backfill, so these rows stay as they are.
        $item->update(['status' => ContentItem::STATUS_PUBLISHED]);
        ContentItemCollection::create([
            'content_item_id' => $item->id, 'submission_id' => $legacy->id,
            'collected_by' => $t['smm']->id, 'collected_at' => now()->subDay(),
        ]);
        $published = PublishedContent::create([
            'content_item_id' => $item->id, 'submission_id' => $legacy->id, 'brand_id' => $brand->id,
            'facebook_post_url' => 'https://facebook.com/legacy', 'published_by' => $t['smm']->id,
            'published_at' => now()->subDay(),
        ]);

        $this->assertNull($legacy->fresh()->approval);
        $this->actingAs($t['marketing'])
            ->getJson(route('marketing.published-contents.unreviewed', $brand))
            ->assertOk()
            ->assertJsonFragment(['id' => $published->id]);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.published-contents.review', [$brand, $published]))
            ->assertOk();
        $this->assertNotNull($published->fresh()->reviewed_at);
    }

    public function test_a_legacy_in_flight_submission_still_needs_marketing_approval_before_smm_collects(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);

        // Policy: no backfill, so a submission still waiting at deploy time
        // must be approved by Marketing like any other.
        $this->expectException(ValidationException::class);
        $this->service()->collect($item->fresh(), $t['smm']);
    }

    // ── 30. Only Marketing can perform pre-publish approval ───────────────────

    public function test_unauthorized_roles_cannot_perform_marketing_pre_publish_approval(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);

        $this->actingAs($t['content'])->postJson($this->approveUrl($brand, $item, $v1))->assertForbidden();
        $this->actingAs($t['design'])->postJson($this->approveUrl($brand, $item, $v1))->assertForbidden();
        $this->actingAs($t['smm'])->postJson($this->approveUrl($brand, $item, $v1))->assertForbidden();
        $this->actingAs($t['manager'])->postJson($this->approveUrl($brand, $item, $v1))->assertForbidden();

        $this->assertSame(0, ContentItemSubmissionApproval::count());
    }
}
