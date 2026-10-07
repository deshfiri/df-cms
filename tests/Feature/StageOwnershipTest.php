<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentItemStageOwner;
use App\Models\ContentItemSubmissionApproval;
use App\Notifications\StageAssignedToYou;
use App\Services\Workflow\StageOwnershipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Stage ownership: who may act on each stage of each exact version. Covers the
 * claim-first rule, direct assignment, eligibility, reassignment, and the
 * history that every change leaves behind.
 */
class StageOwnershipTest extends TestCase
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

    // ── 1–2. Visibility and the first claim ───────────────────────────────────

    public function test_unclaimed_work_is_visible_to_every_eligible_panel_user(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);

        $this->assertContains($item->id, collect($this->actingAs($t['marketing'])->getJson(route('panels.marketing.pending-check'))->json('data'))->pluck('id')->all());
        $this->assertContains($item->id, collect($this->actingAs($marketingB)->getJson(route('panels.marketing.pending-check'))->json('data'))->pluck('id')->all());
        $this->assertNull($this->ownerOf(StageOwnershipService::pre_publish($item->latestSubmission()->id)));
    }

    public function test_the_first_eligible_user_to_claim_becomes_the_owner(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.content-items.submissions.claim', [$brand, $item, $submission]))
            ->assertOk()
            ->assertJsonPath('data.user_id', $t['marketing']->id);

        $this->assertSame($t['marketing']->id, $this->ownerOf(StageOwnershipService::pre_publish($submission->id))->user_id);
        $this->actingAs($marketingB)
            ->postJson(route('marketing.content-items.submissions.claim', [$brand, $item, $submission]))
            ->assertStatus(422);
    }

    // ── 3–4. Second claim refused, and concurrency yields one owner ───────────

    public function test_a_second_user_cannot_claim_an_owned_stage(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->claimPrePublish($item, $submission, $t['marketing']);

        $this->expectException(ValidationException::class);
        $this->ownership()->claim(ContentItemStageOwner::STAGE_PRE_PUBLISH, StageOwnershipService::pre_publish($submission->id), $item, $marketingB);
    }

    public function test_concurrent_claims_on_one_stage_leave_exactly_one_live_owner(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $ref = StageOwnershipService::pre_publish($submission->id);

        // Both claimers read "unowned" before either writes, so the second insert is the race.
        $this->claimPrePublish($item, $submission, $t['marketing']);
        try {
            ContentItemStageOwner::create([
                'content_item_id' => $item->id, 'stage' => ContentItemStageOwner::STAGE_PRE_PUBLISH,
                'stage_ref' => $ref, 'active_ref' => $ref, 'submission_id' => $submission->id,
                'user_id' => $marketingB->id, 'source' => ContentItemStageOwner::SOURCE_CLAIMED, 'acquired_at' => now(),
            ]);
            $this->fail('A second live owner for one stage was inserted.');
        } catch (QueryException) {
            // The database refuses it. This is the backstop behind the locked claim.
        }

        $this->assertSame(1, ContentItemStageOwner::where('active_ref', $ref)->count());
        $this->assertSame($t['marketing']->id, $this->ownerOf($ref)->user_id);
    }

    // ── 5–6. Owner acts; non-owner is refused ─────────────────────────────────

    public function test_the_owner_can_perform_the_stage_action(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->claimPrePublish($item, $submission, $t['marketing']);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.content-items.submissions.approve', [$brand, $item, $submission]))
            ->assertOk();
    }

    public function test_a_non_owner_cannot_perform_an_ownership_sensitive_action(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->claimPrePublish($item, $submission, $t['marketing']);

        $this->actingAs($marketingB)
            ->postJson(route('marketing.content-items.submissions.approve', [$brand, $item, $submission]))
            ->assertStatus(422);
        $this->assertSame(0, ContentItemSubmissionApproval::count());
    }

    public function test_an_unclaimed_stage_cannot_be_acted_on_until_claimed(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.content-items.submissions.approve', [$brand, $item, $submission]))
            ->assertStatus(422);
    }

    // ── 7–9. Direct assignment ────────────────────────────────────────────────

    public function test_the_sender_can_assign_an_eligible_marketing_user_on_submission(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);

        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/a.jpg'], $t['content'], $marketingB);

        $owner = $this->ownerOf(StageOwnershipService::pre_publish($submission->id));
        $this->assertSame($marketingB->id, $owner->user_id);
        $this->assertSame(ContentItemStageOwner::SOURCE_ASSIGNED, $owner->source);
        $this->assertSame($t['content']->id, $owner->assigned_by);
    }

    public function test_assigned_work_needs_no_claim_and_the_assignee_can_act(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/a.jpg'], $t['content'], $marketingB);

        $this->actingAs($marketingB)
            ->postJson(route('marketing.content-items.submissions.approve', [$brand, $item, $submission]))
            ->assertOk();
    }

    public function test_a_same_role_user_cannot_steal_directly_assigned_work(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/a.jpg'], $t['content'], $marketingB);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.content-items.submissions.claim', [$brand, $item, $submission]))
            ->assertStatus(422);
        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.content-items.submissions.approve', [$brand, $item, $submission]))
            ->assertStatus(422);
    }

    // ── 10–12. Eligibility and cross-stage rejection ──────────────────────────

    public function test_assigning_a_wrong_role_is_rejected(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);

        $this->expectException(ValidationException::class);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/a.jpg'], $t['content'], $t['design']);
    }

    public function test_an_invalid_or_inactive_user_is_rejected(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $inactive = $this->user('Marketing', ['manage publishing-review']);
        $inactive->update(['is_active' => false]);
        $item = $this->newItem($brand, $t['content']);

        $this->expectException(ValidationException::class);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/a.jpg'], $t['content'], $inactive);
    }

    public function test_an_arbitrary_user_id_in_the_request_is_rejected_server_side(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);

        $this->actingAs($t['content'])
            ->postJson(route('marketing.content-items.submit', [$brand, $item]), [
                'link_url' => 'https://example.com/a.jpg', 'assign_to' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_cross_stage_assignment_is_rejected_at_approval(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->claimPrePublish($item, $submission, $t['marketing']);

        // A Content user is not an SMM publisher, so the SMM stage cannot go to them.
        $this->expectException(ValidationException::class);
        $this->service()->approveForHandover($item->fresh(), $submission->fresh(), $t['marketing'], $t['content']);
    }

    // ── 13–14. Metadata ───────────────────────────────────────────────────────

    public function test_assignment_metadata_is_preserved(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/a.jpg'], $t['content'], $marketingB);

        $owner = $this->ownerOf(StageOwnershipService::pre_publish($submission->id));
        $this->assertSame($submission->id, $owner->submission_id);
        $this->assertSame($item->id, $owner->content_item_id);
        $this->assertNotNull($owner->acquired_at);
        $this->assertNull($owner->released_at);
    }

    public function test_claim_metadata_is_preserved_and_distinct_from_assignment(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->claimPrePublish($item, $submission, $t['marketing']);

        $owner = $this->ownerOf(StageOwnershipService::pre_publish($submission->id));
        $this->assertSame(ContentItemStageOwner::SOURCE_CLAIMED, $owner->source);
        $this->assertNull($owner->assigned_by, 'A claim is self-directed, so nobody assigned it.');
        $this->assertSame($t['marketing']->id, $owner->user_id);
    }

    // ── 15–18. Revisions, V2 and V1 history ───────────────────────────────────

    public function test_a_revision_can_be_assigned_to_a_destination_maker(): void
    {
        Notification::fake();
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submitVersion($item, $t['design']);
        $designB = $this->user('Design', ['manage designer-content']);

        $this->reviseItem($item, ['note' => 'Logo too small'], $t['marketing'], $designB);

        $revisionRef = StageOwnershipService::revision($item->fresh()->revisions()->latest('id')->first()->id);
        $this->assertSame($designB->id, $this->ownerOf($revisionRef)->user_id);
        Notification::assertSentTo($designB, StageAssignedToYou::class);
        Notification::assertNotSentTo($t['design'], StageAssignedToYou::class);
    }

    public function test_an_unclaimed_revision_remains_claimable_and_only_one_maker_gets_it(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $designB = $this->user('Design', ['manage designer-content']);
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submitVersion($item, $t['design']);
        $this->reviseItem($item, ['note' => 'Logo too small'], $t['marketing']);

        $this->actingAs($designB)->postJson(route('marketing.content-items.claim-revision', [$brand, $item]))->assertOk();
        $this->actingAs($t['design'])->postJson(route('marketing.content-items.claim-revision', [$brand, $item]))->assertStatus(422);
    }

    public function test_v2_has_its_own_stage_ownership_and_v1_history_stays_intact(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $v1 = $this->submitVersion($item, $t['design']);
        $this->claimPrePublish($item, $v1, $t['marketing']);
        $this->reviseItem($item, ['note' => 'Fix'], $t['marketing']);
        $v2 = $this->submitItem($item, ['link_url' => 'https://example.com/v2.jpg'], $t['design']);
        $this->claimPrePublish($item, $v2, $marketingB);

        $this->assertSame($t['marketing']->id, $this->ownerOf(StageOwnershipService::pre_publish($v1->id))->user_id);
        $this->assertSame($marketingB->id, $this->ownerOf(StageOwnershipService::pre_publish($v2->id))->user_id);
        $this->assertSame(2, ContentItemStageOwner::where('content_item_id', $item->id)->where('stage', 'pre_publish')->count());
    }

    // ── Reassignment: Manager override, audited, history kept ─────────────────

    public function test_a_manager_can_reassign_and_the_previous_owner_stays_in_history(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->claimPrePublish($item, $submission, $t['marketing']);

        $this->actingAs($t['manager'])->postJson(route('marketing.content-items.stage-owners.reassign', [$brand, $item]), [
            'stage' => ContentItemStageOwner::STAGE_PRE_PUBLISH, 'submission_id' => $submission->id,
            'user_id' => $marketingB->id, 'reason' => 'Covering the handover',
        ])->assertOk();

        $ref = StageOwnershipService::pre_publish($submission->id);
        $this->assertSame($marketingB->id, $this->ownerOf($ref)->user_id);
        $released = ContentItemStageOwner::where('stage_ref', $ref)->whereNotNull('released_at')->first();
        $this->assertSame($t['marketing']->id, $released->user_id, 'The released owner stays in history.');
        $this->assertSame($t['manager']->id, $released->released_by);
        $this->assertSame('Covering the handover', $released->release_reason);
    }

    public function test_a_marketing_user_cannot_reassign_work_they_do_not_own(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->claimPrePublish($item, $submission, $t['marketing']);

        $this->actingAs($marketingB)->postJson(route('marketing.content-items.stage-owners.reassign', [$brand, $item]), [
            'stage' => ContentItemStageOwner::STAGE_PRE_PUBLISH, 'submission_id' => $submission->id,
            'user_id' => $marketingB->id, 'reason' => 'Taking it over',
        ])->assertForbidden();
        $this->assertSame($t['marketing']->id, $this->ownerOf(StageOwnershipService::pre_publish($submission->id))->user_id);
    }

    public function test_a_reassignment_cannot_name_another_items_version(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $itemA = $this->newItem($brand, $t['content']);
        $itemB = $this->newItem($brand, $t['content']);
        $foreign = $this->submitVersion($itemB, $t['content']);

        $this->actingAs($t['manager'])->postJson(route('marketing.content-items.stage-owners.reassign', [$brand, $itemA]), [
            'stage' => ContentItemStageOwner::STAGE_PRE_PUBLISH, 'submission_id' => $foreign->id,
            'user_id' => $t['marketing']->id, 'reason' => 'Wrong item',
        ])->assertNotFound();
    }

    public function test_claiming_a_stage_you_already_own_is_a_safe_no_op(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);

        $this->actingAs($t['marketing'])->postJson(route('marketing.content-items.submissions.claim', [$brand, $item, $submission]))->assertOk();
        $this->actingAs($t['marketing'])->postJson(route('marketing.content-items.submissions.claim', [$brand, $item, $submission]))->assertOk();

        $this->assertSame(1, ContentItemStageOwner::where('stage_ref', StageOwnershipService::pre_publish($submission->id))->count());
    }

    public function test_the_ownership_gate_refuses_work_while_the_checklist_is_on_hold(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $brand->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Hold']);

        $this->actingAs($t['marketing'])
            ->postJson(route('marketing.content-items.submissions.claim', [$brand, $item, $submission]))
            ->assertStatus(422);
        $this->assertNull($this->ownerOf(StageOwnershipService::pre_publish($submission->id)));
    }
}
