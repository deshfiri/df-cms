<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\PerformancePointEvent;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use App\Services\Performance\PerformancePointService;
use App\Support\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Workflow Performance Points. Every award is proved against the user's EXISTING
 * Performance score (PerformanceCalculationService::finalScore), not only against
 * the ledger. The ledger is the auditable source, and the score is what it feeds.
 */
class PerformancePointsTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    private const PERIOD = '2026-10';

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

    private function score(User $user, string $period = self::PERIOD): ?float
    {
        return app(PerformanceCalculationService::class)->finalScore($user, $period)['final_score'];
    }

    private function pointsEarned(User $user, string $event): int
    {
        return (int) PerformancePointEvent::where('user_id', $user->id)->where('event_type', $event)->sum('points');
    }

    private function perPoint(): float
    {
        return (float) config('performance.score_per_point');
    }

    // ── 1–4. Content and Designer approval points ─────────────────────────────

    public function test_raw_content_approval_awards_the_responsible_content_user_and_their_score_rises(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content'], ContentItem::CATEGORY_RAW_CONTENT);
        $submission = $this->submitVersion($item, $t['content']);

        $before = $this->score($t['content']);
        $this->approve($item, $submission, $t['marketing']);

        $this->assertSame(2, $this->pointsEarned($t['content'], PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL));
        $this->assertNotNull($this->score($t['content']));
        $this->assertEqualsWithDelta(($before ?? 0) + 2 * $this->perPoint(), $this->score($t['content']), 0.001);
    }

    public function test_advertising_content_approval_awards_the_responsible_content_user(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $submission = $this->submitVersion($item, $t['content']);

        $this->approve($item, $submission, $t['marketing']);

        $this->assertSame(2, $this->pointsEarned($t['content'], PerformancePointEvent::EVENT_ADVERTISING_CONTENT_APPROVAL));
        $this->assertEqualsWithDelta(2 * $this->perPoint(), $this->score($t['content']), 0.001);
    }

    public function test_poster_approval_awards_the_exact_designer_and_another_designer_gets_nothing(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $designB = $this->user('Design', ['manage designer-content']);
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $submission = $this->submitVersion($item, $t['design']);

        $this->approve($item, $submission, $t['marketing']);

        $this->assertEqualsWithDelta(2 * $this->perPoint(), $this->score($t['design']), 0.001);
        $this->assertNull($this->score($designB));
        $this->assertSame(0, $this->pointsEarned($designB, PerformancePointEvent::EVENT_POSTER_APPROVAL));
    }

    public function test_a_revision_requested_version_earns_no_approval_point_and_v2_awards_its_own_maker(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $designB = $this->user('Design', ['manage designer-content']);
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $v1 = $this->submitVersion($item, $t['design']);
        $this->reviseItem($item, ['note' => 'Fix'], $t['marketing'], $designB);

        $this->assertSame(0, $this->pointsEarned($t['design'], PerformancePointEvent::EVENT_POSTER_APPROVAL), 'Submitting alone earns nothing.');
        $this->assertNull($this->score($t['design']));

        $v2 = $this->submitItem($item, ['link_url' => 'https://example.com/v2.jpg'], $designB);
        $this->approve($item, $v2, $t['marketing']);

        $this->assertSame(0, $this->pointsEarned($t['design'], PerformancePointEvent::EVENT_POSTER_APPROVAL));
        $this->assertSame(2, $this->pointsEarned($designB, PerformancePointEvent::EVENT_POSTER_APPROVAL));
        $this->assertSame(PerformancePointEvent::SOURCE_SUBMISSION, PerformancePointEvent::where('source_id', $v2->id)->value('source_type'));
        $this->assertNotSame($v1->id, $v2->id);
    }

    public function test_a_duplicate_approval_does_not_award_twice(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);

        $this->approve($item, $submission, $t['marketing']);
        $this->approve($item, $submission, $t['marketing']);
        $this->service()->approveForHandover($item->fresh(), $submission->fresh(), $t['marketing']);

        $this->assertSame(1, PerformancePointEvent::where('event_type', PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL)->count());
        $this->assertSame(1, PerformancePointEvent::where('event_type', PerformancePointEvent::EVENT_MARKETING_HANDOVER)->count());
        $this->assertEqualsWithDelta(2 * $this->perPoint(), $this->score($t['content']), 0.001);
    }

    // ── 7–9. Marketing handover and final review ──────────────────────────────

    public function test_marketing_handover_awards_the_approving_user_and_their_score_rises(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);

        $this->approve($item, $submission, $t['marketing']);

        $this->assertSame(1, $this->pointsEarned($t['marketing'], PerformancePointEvent::EVENT_MARKETING_HANDOVER));
        $this->assertEqualsWithDelta(1 * $this->perPoint(), $this->score($t['marketing']), 0.001);
    }

    public function test_marketing_final_review_awards_the_actual_reviewer_and_the_smm_publisher_separately(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $marketingB = $this->user('Marketing', ['manage publishing-review']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->approve($item, $submission, $t['marketing']);
        $this->collect($item, $t['smm']);
        $published = $this->publish($item, $brand, $submission, $t['smm']);

        $this->reviewPublication($brand, $published, $marketingB)->assertOk();

        $this->assertSame(1, $this->pointsEarned($marketingB, PerformancePointEvent::EVENT_MARKETING_FINAL_REVIEW));
        $this->assertSame(0, $this->pointsEarned($t['marketing'], PerformancePointEvent::EVENT_MARKETING_FINAL_REVIEW));
        $this->assertSame(2, $this->pointsEarned($t['smm'], PerformancePointEvent::EVENT_SMM_PUBLISH_SUCCESS));
        $this->assertSame(1, $this->pointsEarned($t['marketing'], PerformancePointEvent::EVENT_MARKETING_HANDOVER));
    }

    // ── 10–13. SMM success, stale and duplicate reviews ───────────────────────

    public function test_smm_publish_alone_awards_no_success_point_and_the_review_awards_it(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->approve($item, $submission, $t['marketing']);
        $this->collect($item, $t['smm']);

        $published = $this->publish($item, $brand, $submission, $t['smm']);
        $this->assertSame(0, $this->pointsEarned($t['smm'], PerformancePointEvent::EVENT_SMM_PUBLISH_SUCCESS));
        $this->assertNull($this->score($t['smm']));

        $this->reviewPublication($brand, $published, $t['marketing'])->assertOk();
        $this->assertSame(2, $this->pointsEarned($t['smm'], PerformancePointEvent::EVENT_SMM_PUBLISH_SUCCESS));
    }

    public function test_a_stale_superseded_review_awards_no_success_point(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $v1 = $this->submitVersion($item, $t['content']);
        $this->approve($item, $v1, $t['marketing']);
        $this->collect($item, $t['smm']);
        $published1 = $this->publish($item, $brand, $v1, $t['smm']);
        $this->submitVersion($item, $t['content']);

        $this->reviewPublication($brand, $published1, $t['marketing'])->assertOk();

        $this->assertSame(0, $this->pointsEarned($t['smm'], PerformancePointEvent::EVENT_SMM_PUBLISH_SUCCESS));
        $this->assertSame(0, $this->pointsEarned($t['marketing'], PerformancePointEvent::EVENT_MARKETING_FINAL_REVIEW));
    }

    public function test_a_duplicate_final_review_does_not_duplicate_the_points(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->approve($item, $submission, $t['marketing']);
        $this->collect($item, $t['smm']);
        $published = $this->publish($item, $brand, $submission, $t['smm']);

        $this->reviewPublication($brand, $published, $t['marketing'])->assertOk();
        $this->reviewPublication($brand, $published, $t['marketing'])->assertOk();

        $this->assertSame(1, PerformancePointEvent::where('event_type', PerformancePointEvent::EVENT_MARKETING_FINAL_REVIEW)->count());
        $this->assertSame(1, PerformancePointEvent::where('event_type', PerformancePointEvent::EVENT_SMM_PUBLISH_SUCCESS)->count());
    }

    // ── 14. Isolation and no forging ──────────────────────────────────────────

    public function test_points_are_isolated_by_user_and_by_source(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $itemA = $this->newItem($brandA, $t['content']);
        $subA = $this->submitVersion($itemA, $t['content']);
        $this->approve($itemA, $subA, $t['marketing']);
        $itemB = $this->newItem($brandB, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $subB = $this->submitVersion($itemB, $t['content']);
        $this->approve($itemB, $subB, $t['marketing']);

        $summary = app(PerformancePointService::class)->summaryForUser($t['content'], ReportingPeriod::monthly(self::PERIOD));

        $this->assertSame(4, $summary['points']);
        $this->assertSame(2, (int) PerformancePointEvent::where('brand_id', $brandA->id)->where('user_id', $t['content']->id)->sum('points'));
        $this->assertSame(2, (int) PerformancePointEvent::where('brand_id', $brandB->id)->where('user_id', $t['content']->id)->sum('points'));
    }

    public function test_no_request_can_credit_points_directly(): void
    {
        $t = $this->workflowTeam();
        $this->readyBrand($t['manager']);

        // No route accepts a write here (the path is GET-only, so 405), and no points endpoint exists.
        $this->actingAs($t['manager'])->postJson('/performance/points', [
            'user_id' => $t['content']->id, 'event_type' => 'potential_client', 'points' => 99,
        ])->assertStatus(405);
        // Points are never a request field: even a fabricated body on a real endpoint
        // is refused by the role gate, and nothing reads a points value from it.
        $this->actingAs($t['manager'])->postJson('/smm-conversations', [
            'points' => 99, 'user_id' => $t['content']->id,
        ])->assertForbidden();

        $this->assertSame(0, PerformancePointEvent::count());
    }

    // ── 15–16. Daily / Monthly / Yearly and the Asia/Dhaka boundary ───────────

    public function test_daily_monthly_and_yearly_breakdowns_use_awarded_at_in_asia_dhaka(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);

        $this->atDhaka('2026-10-07 23:50');
        $this->approve($item, $submission, $t['marketing']);

        $service = app(PerformancePointService::class);
        $this->assertSame(2, $service->breakdownForUser($t['content'], ReportingPeriod::daily('2026-10-07'))[PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL]['points']);
        $this->assertSame([], $service->breakdownForUser($t['content'], ReportingPeriod::daily('2026-10-08')));
        $this->assertSame(2, $service->breakdownForUser($t['content'], ReportingPeriod::monthly('2026-10'))[PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL]['points']);
        $this->assertSame(2, $service->breakdownForUser($t['content'], ReportingPeriod::yearly('2026'))[PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL]['points']);
    }

    public function test_a_point_at_00_10_on_the_eighth_belongs_to_the_eighth_not_the_seventh(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);

        $this->atDhaka('2026-10-08 00:10');
        $this->approve($item, $submission, $t['marketing']);

        $service = app(PerformancePointService::class);
        $this->assertSame([], $service->breakdownForUser($t['content'], ReportingPeriod::daily('2026-10-07')));
        $this->assertArrayHasKey(PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL, $service->breakdownForUser($t['content'], ReportingPeriod::daily('2026-10-08')));
    }

    // ── 17–18. Existing Performance still works, and the ledger matches the score ─

    public function test_a_user_with_no_points_keeps_the_exact_existing_score_behaviour(): void
    {
        $t = $this->workflowTeam();
        $result = app(PerformanceCalculationService::class)->finalScore($t['content'], self::PERIOD);

        $this->assertNull($result['final_score']);
        $this->assertSame(0, $result['components']['workflowPoints']['points']);
    }

    public function test_the_ledger_total_and_the_score_contribution_stay_consistent(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->approve($item, $submission, $t['marketing']);

        $result = app(PerformanceCalculationService::class)->finalScore($t['content'], self::PERIOD);
        $ledgerPoints = (int) PerformancePointEvent::where('user_id', $t['content']->id)->sum('points');

        $this->assertSame($ledgerPoints, $result['components']['workflowPoints']['points']);
        $this->assertEqualsWithDelta($ledgerPoints * $this->perPoint(), $result['components']['workflowPoints']['score'], 0.001);
    }

    // ── Brand filter: ledger breakdown only, never the official score ─────────

    /** Sections 12, 23–25: the breakdown uses the ledger event's own brand_id, and the official score never changes. */
    public function test_the_workflow_points_breakdown_respects_brand_but_the_official_score_does_not(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $itemA = $this->newItem($brandA, $t['content']);
        $subA = $this->submitVersion($itemA, $t['content']);
        $this->approve($itemA, $subA, $t['marketing']);
        $itemB = $this->newItem($brandB, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $subB = $this->submitVersion($itemB, $t['content']);
        $this->approve($itemB, $subB, $t['marketing']);

        $service = app(PerformancePointService::class);
        $period = ReportingPeriod::monthly(self::PERIOD);

        $breakdownA = $service->breakdownForUser($t['content'], $period, $brandA->id);
        $breakdownB = $service->breakdownForUser($t['content'], $period, $brandB->id);
        $breakdownAll = $service->breakdownForUser($t['content'], $period);

        // Brand B's points are excluded when Brand A is selected (and vice versa).
        $this->assertSame(2, $breakdownA[PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL]['points']);
        $this->assertArrayNotHasKey(PerformancePointEvent::EVENT_ADVERTISING_CONTENT_APPROVAL, $breakdownA);
        $this->assertSame(2, $breakdownB[PerformancePointEvent::EVENT_ADVERTISING_CONTENT_APPROVAL]['points']);
        $this->assertArrayNotHasKey(PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL, $breakdownB);
        $this->assertSame(4, $breakdownAll[PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL]['points'] + $breakdownAll[PerformancePointEvent::EVENT_ADVERTISING_CONTENT_APPROVAL]['points']);

        // The official final score is computed exactly as before — brand filtering never touches it.
        $this->assertEqualsWithDelta(4 * $this->perPoint(), $this->score($t['content']), 0.001);
    }

    /** The scorecard page itself: a brand_id in the URL narrows the rendered breakdown table, never the hero score. */
    public function test_the_scorecard_page_narrows_the_points_breakdown_by_brand_in_the_url(): void
    {
        $t = $this->workflowTeam();
        Permission::firstOrCreate(['name' => 'view performance', 'guard_name' => 'web']);
        $viewer = $this->user('Manager', ['view performance']);
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $itemA = $this->newItem($brandA, $t['content']);
        $this->approve($itemA, $this->submitVersion($itemA, $t['content']), $t['marketing']);
        $itemB = $this->newItem($brandB, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $this->approve($itemB, $this->submitVersion($itemB, $t['content']), $t['marketing']);

        $all = $this->actingAs($viewer)->get(route('performance.show', $t['content']).'?period='.self::PERIOD);
        $all->assertOk();
        $allBreakdown = $all->viewData('pointsBreakdown');
        $this->assertArrayHasKey(PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL, $allBreakdown);
        $this->assertArrayHasKey(PerformancePointEvent::EVENT_ADVERTISING_CONTENT_APPROVAL, $allBreakdown);

        $filtered = $this->actingAs($viewer)->get(route('performance.show', $t['content']).'?period='.self::PERIOD.'&brand_id='.$brandA->id);
        $filtered->assertOk();
        $filteredBreakdown = $filtered->viewData('pointsBreakdown');
        $this->assertArrayHasKey(PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL, $filteredBreakdown);
        $this->assertArrayNotHasKey(PerformancePointEvent::EVENT_ADVERTISING_CONTENT_APPROVAL, $filteredBreakdown);
        // The hero score (result['final_score']) is identical regardless of the brand filter.
        $this->assertSame($all->viewData('result')['final_score'], $filtered->viewData('result')['final_score']);
    }

    // ── 16. Ranking follows the score ─────────────────────────────────────────

    public function test_earned_points_move_a_user_above_another_in_the_score_order(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $designA = $this->user('Design', ['manage designer-content']);
        $designB = $this->user('Design', ['manage designer-content']);

        // A earns two approvals, B earns none.
        foreach ([$designA, $designA] as $maker) {
            $item = $this->newItem($brand, $maker, ContentItem::CATEGORY_POSTER);
            $submission = $this->submitVersion($item, $maker);
            $this->approve($item, $submission, $t['marketing']);
        }

        $this->assertGreaterThan(
            (float) ($this->score($designB) ?? 0),
            (float) $this->score($designA),
            'Earned points must rank the earner above the user who earned none.',
        );
    }
}
