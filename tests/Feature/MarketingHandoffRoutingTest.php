<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Notifications\ChecklistRevisionRequested;
use App\Notifications\ContentApprovedForPublishing;
use App\Notifications\ContentPublishedAndReviewed;
use App\Notifications\ContentReadyForPrePublishCheck;
use App\Notifications\ContentReadyForPublishingReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Who hears about each handoff in the Marketing pre-publish workflow. Each
 * step is checked on its own so a regression names the exact hop that broke.
 * The delivery mechanism is unchanged: notifications still go through the
 * existing database + realtime channel, and this only checks routing.
 */
class MarketingHandoffRoutingTest extends TestCase
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

    public function test_submit_notifies_marketing_with_the_pre_publish_check_and_never_smm(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);

        $this->submitVersion($item, $t['content']);

        Notification::assertSentTo($t['marketing'], ContentReadyForPrePublishCheck::class);
        Notification::assertNothingSentTo($t['smm']);
        Notification::assertNothingSentTo($t['manager']);
    }

    public function test_marketing_approval_notifies_only_smm(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);

        $this->approve($item, $v1, $t['marketing']);

        Notification::assertSentTo($t['smm'], ContentApprovedForPublishing::class);
        Notification::assertNotSentTo($t['marketing'], ContentApprovedForPublishing::class);
        Notification::assertNothingSentTo($t['manager']);
    }

    public function test_marketing_revision_goes_back_to_the_content_maker_and_not_to_smm(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content'], ContentItem::CATEGORY_RAW_CONTENT);
        $this->submitVersion($item, $t['content']);

        $this->reviseItem($item->fresh(), ['note' => 'Redo'], $t['marketing']);

        Notification::assertSentTo($t['content'], ChecklistRevisionRequested::class);
        Notification::assertNotSentTo($t['smm'], ChecklistRevisionRequested::class);
        Notification::assertNothingSentTo($t['manager']);
    }

    public function test_smm_revision_goes_back_to_the_maker_and_not_to_marketing_or_manager(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $v1 = $this->submitVersion($item, $t['design']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);

        $this->reviseItem($item->fresh(), ['note' => 'Wrong size'], $t['smm']);

        Notification::assertSentTo($t['design'], ChecklistRevisionRequested::class);
        Notification::assertNotSentTo($t['marketing'], ChecklistRevisionRequested::class);
        Notification::assertNothingSentTo($t['manager']);
    }

    public function test_a_resubmission_after_any_revision_returns_to_marketing_and_never_directly_to_smm(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $this->reviseItem($item->fresh(), ['note' => 'SMM revision'], $t['smm']);

        $this->submitVersion($item, $t['content']);

        Notification::assertSentToTimes($t['marketing'], ContentReadyForPrePublishCheck::class, 2);
        // Only V1's handover reached SMM; the resubmission added nothing for them.
        Notification::assertSentToTimes($t['smm'], ContentApprovedForPublishing::class, 1);
        Notification::assertNotSentTo($t['smm'], ContentReadyForPrePublishCheck::class);
    }

    public function test_smm_publish_notifies_marketing_for_final_review_and_never_the_manager(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);

        $this->publish($item, $brand, $v1, $t['smm']);

        Notification::assertSentTo($t['marketing'], ContentReadyForPublishingReview::class);
        Notification::assertNotSentTo($t['smm'], ContentReadyForPublishingReview::class);
        Notification::assertNothingSentTo($t['manager']);
    }

    public function test_marketing_final_review_is_the_only_step_that_notifies_the_manager(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $published = $this->publish($item, $brand, $v1, $t['smm']);
        Notification::assertNothingSentTo($t['manager']);

        $this->reviewPublication($brand, $published, $t['marketing'])
            ->assertOk();

        Notification::assertSentToTimes($t['manager'], ContentPublishedAndReviewed::class, 1);
        Notification::assertNotSentTo($t['smm'], ContentPublishedAndReviewed::class);
        Notification::assertNotSentTo($t['marketing'], ContentPublishedAndReviewed::class);
    }

    public function test_the_full_cycle_hands_each_step_to_exactly_one_next_party(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->reviseItem($item->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->travel(1)->minutes();
        $v2 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v2, $t['marketing']);
        $this->collect($item, $t['smm']);
        $this->travel(1)->minutes();
        $published = $this->publish($item, $brand, $v2, $t['smm']);
        $this->reviewPublication($brand, $published, $t['marketing'])
            ->assertOk();

        Notification::assertSentToTimes($t['marketing'], ContentReadyForPrePublishCheck::class, 2);
        Notification::assertSentToTimes($t['smm'], ContentApprovedForPublishing::class, 1);
        Notification::assertSentToTimes($t['content'], ChecklistRevisionRequested::class, 1);
        Notification::assertSentToTimes($t['marketing'], ContentReadyForPublishingReview::class, 1);
        Notification::assertSentToTimes($t['manager'], ContentPublishedAndReviewed::class, 1);
        $this->assertNotNull($v1->fresh());
    }
}
