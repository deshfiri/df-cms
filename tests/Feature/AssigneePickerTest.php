<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentItemStageOwner;
use App\Models\PublishedContent;
use App\Services\Workflow\StageOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * UI-facing assignee pickers for every cross-panel handoff (Content/Design ->
 * Marketing, Marketing -> SMM [already existed], Marketing revision ->
 * Content/Design, SMM revision -> Content/Design, SMM publish -> Marketing
 * Final Review). The underlying Claim/Assign security is already covered
 * exhaustively by StageOwnershipTest; this file covers the UI wiring added
 * here: which eligible users each panel's view now exposes, that the list is
 * split by destination role/category (never one mixed list), and that an
 * assignment made through the exact new UI field still goes through the same
 * secure StageOwnershipService path end-to-end.
 */
class AssigneePickerTest extends TestCase
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

    private function ownerOf(string $stageRef): ?ContentItemStageOwner
    {
        return ContentItemStageOwner::where('active_ref', $stageRef)->first();
    }

    // ── Raw Content -> Marketing ────────────────────────────────────────────

    public function test_raw_content_panel_exposes_eligible_marketing_users_only(): void
    {
        $t = $this->workflowTeam();
        $marketingB = $this->user('Marketing', ['manage publishing-review']);

        $names = $this->actingAs($t['content'])->get(route('panels.raw-content'))->viewData('marketingUsers')->pluck('name')->all();

        $this->assertContains($t['marketing']->name, $names);
        $this->assertContains($marketingB->name, $names);
        $this->assertNotContains($t['content']->name, $names);
        $this->assertNotContains($t['design']->name, $names);
        $this->assertNotContains($t['smm']->name, $names);
    }

    public function test_raw_content_submission_can_be_assigned_directly_to_a_marketing_user(): void
    {
        $t = $this->workflowTeam();
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);

        $this->actingAs($t['content'])->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/a.jpg', 'assign_to' => $marketingB->id,
        ])->assertOk();

        $submission = $item->fresh()->latestSubmission();
        $this->assertSame($marketingB->id, $this->ownerOf(StageOwnershipService::pre_publish($submission->id))->user_id);
    }

    // ── Designer -> Marketing ────────────────────────────────────────────────

    public function test_designer_panel_exposes_eligible_marketing_users_only(): void
    {
        $t = $this->workflowTeam();

        $names = $this->actingAs($t['design'])->get(route('panels.designer'))->viewData('marketingUsers')->pluck('name')->all();

        $this->assertContains($t['marketing']->name, $names);
        $this->assertNotContains($t['design']->name, $names);
    }

    public function test_poster_submission_can_be_assigned_directly_to_a_marketing_user(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $this->actingAs($t['design'])->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/a.jpg', 'assign_to' => $t['marketing']->id,
        ])->assertOk();

        $submission = $item->fresh()->latestSubmission();
        $this->assertSame($t['marketing']->id, $this->ownerOf(StageOwnershipService::pre_publish($submission->id))->user_id);
    }

    // ── No assignee: stage stays unclaimed, then claimable ──────────────────

    public function test_an_unassigned_submission_leaves_the_stage_unclaimed_and_claimable(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);

        $this->actingAs($t['content'])->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/a.jpg',
        ])->assertOk();

        $submission = $item->fresh()->latestSubmission();
        $this->assertNull($this->ownerOf(StageOwnershipService::pre_publish($submission->id)));

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.content-items.submissions.claim', [$brand, $item, $submission]))
            ->assertOk();
        $this->assertSame($t['marketing']->id, $this->ownerOf(StageOwnershipService::pre_publish($submission->id))->user_id);
    }

    // ── Marketing revision -> Content / Design, split by category ──────────

    public function test_marketing_panel_splits_revision_assignees_by_destination_role(): void
    {
        $t = $this->workflowTeam();

        $response = $this->actingAs($t['marketing'])->get(route('panels.marketing'));
        $contentNames = $response->viewData('contentUsers')->pluck('name')->all();
        $designNames = $response->viewData('designUsers')->pluck('name')->all();

        $this->assertContains($t['content']->name, $contentNames);
        $this->assertNotContains($t['design']->name, $contentNames);
        $this->assertContains($t['design']->name, $designNames);
        $this->assertNotContains($t['content']->name, $designNames);
    }

    public function test_marketing_revision_on_a_raw_content_item_can_be_assigned_to_a_content_user(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $contentB = $this->user('Content', ['manage raw-content']);
        $item = $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);
        $this->claimPrePublish($item, $item->latestSubmission(), $t['marketing']);

        $this->reviseItem($item->fresh(), ['note' => 'Fix it'], $t['marketing'], $contentB);

        $revisionRef = StageOwnershipService::revision($item->fresh()->revisions()->latest('id')->first()->id);
        $this->assertSame($contentB->id, $this->ownerOf($revisionRef)->user_id);
    }

    /** The server-side eligibility gate StageOwnershipTest already proves — restated here against the real UI transition's own category. */
    public function test_marketing_cannot_assign_a_raw_content_revision_to_a_design_user(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);
        $this->claimPrePublish($item, $item->latestSubmission(), $t['marketing']);

        $this->expectException(ValidationException::class);
        $this->service()->requestRevision($item->fresh(), ['note' => 'Fix it'], $t['marketing'], $t['design']);
    }

    // ── SMM revision -> Content / Design, split by category (new picker) ───

    public function test_smm_panel_splits_revision_assignees_by_destination_role(): void
    {
        $t = $this->workflowTeam();

        $response = $this->actingAs($t['smm'])->get(route('panels.smm'));
        $contentNames = $response->viewData('contentUsers')->pluck('name')->all();
        $designNames = $response->viewData('designUsers')->pluck('name')->all();

        $this->assertContains($t['content']->name, $contentNames);
        $this->assertContains($t['design']->name, $designNames);
    }

    public function test_an_smm_revision_can_be_assigned_directly_to_the_poster_maker(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $designB = $this->user('Design', ['manage designer-content']);
        $item = $this->handedOverAt('2026-10-06 10:00', $brand, $t['design'], $t['marketing'], ContentItem::CATEGORY_POSTER);
        $this->collect($item, $t['smm']);

        $this->reviseItem($item->fresh(), ['note' => 'Logo'], $t['smm'], $designB);

        $revisionRef = StageOwnershipService::revision($item->fresh()->revisions()->latest('id')->first()->id);
        $this->assertSame($designB->id, $this->ownerOf($revisionRef)->user_id);
    }

    // ── SMM publish -> Marketing Final Review (new picker; any other real
    // handoff gets the same treatment) ──────────────────────────────────────

    public function test_smm_panel_exposes_eligible_marketing_users_for_the_publish_assignee(): void
    {
        $t = $this->workflowTeam();

        $names = $this->actingAs($t['smm'])->get(route('panels.smm'))->viewData('marketingUsers')->pluck('name')->all();

        $this->assertContains($t['marketing']->name, $names);
    }

    public function test_publishing_can_assign_final_review_directly_to_a_marketing_user(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);
        $submission = $item->latestSubmission();
        $this->collect($item, $t['smm']);

        $this->actingAs($t['smm'])->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission->id, 'facebook_post_url' => 'https://facebook.com/x', 'assign_review_to' => $marketingB->id,
        ])->assertOk();

        $published = PublishedContent::where('content_item_id', $item->id)->latest('id')->first();
        $this->assertSame($marketingB->id, $this->ownerOf(StageOwnershipService::finalReview($published->id))->user_id);
    }

    // ── Security: tampering is still rejected end-to-end through the new UI fields ──

    public function test_a_wrong_role_assign_to_on_submit_is_rejected_server_side(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);

        $this->actingAs($t['content'])->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/a.jpg', 'assign_to' => $t['design']->id,
        ])->assertStatus(422);
    }

    public function test_another_same_role_marketing_user_cannot_steal_an_assigned_submission(): void
    {
        $t = $this->workflowTeam();
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $this->actingAs($t['content'])->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/a.jpg', 'assign_to' => $marketingB->id,
        ])->assertOk();

        $submission = $item->fresh()->latestSubmission();
        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.content-items.submissions.claim', [$brand, $item, $submission]))
            ->assertStatus(422);
    }

    // ── Period + Brand filtering still governs an assigned row ──────────────

    public function test_an_assigned_submission_still_follows_period_and_brand_filtering(): void
    {
        $t = $this->workflowTeam();
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);

        $this->atDhaka('2026-10-06 10:00');
        $itemA = $this->newItem($brandA, $t['content']);
        $this->service()->submit($itemA->fresh(), ['link_url' => 'https://example.com/a.jpg'], $t['content'], $marketingB);
        $itemB = $this->newItem($brandB, $t['content']);
        $this->service()->submit($itemB->fresh(), ['link_url' => 'https://example.com/b.jpg'], $t['content'], $marketingB);

        $ids = collect($this->actingAs($marketingB)
            ->getJson(route('panels.marketing.pending-check', $this->withBrand($this->monthly('2026-10'), $brandA)))
            ->json('data'))->pluck('id')->all();

        $this->assertContains($itemA->id, $ids);
        $this->assertNotContains($itemB->id, $ids, 'Brand filtering must still exclude the other brand even though both are assigned.');

        // September — the item was not submitted then — hides it even though it's assigned and actionable today.
        $septIds = collect($this->actingAs($marketingB)
            ->getJson(route('panels.marketing.pending-check', $this->withBrand($this->monthly('2026-09'), $brandA)))
            ->json('data'))->pluck('id')->all();
        $this->assertNotContains($itemA->id, $septIds, 'Period filtering must still exclude it even though it is assigned.');
    }
}
