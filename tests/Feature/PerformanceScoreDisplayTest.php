<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\PerformancePointEvent;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use App\Services\Performance\PerformancePointService;
use App\Support\ReportingPeriod;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Performance UI display (Final Gap sections 11-14). The formula and point
 * values are completely unchanged here — see PerformancePointsTest and
 * PerformanceCalculationService::finalScore()'s own docblock, neither of
 * which this file touches. This file only proves the UI communicates the
 * existing calculation honestly: KPI Score stays a genuine percentage,
 * Workflow Points are shown as their own term, and the combined Performance
 * Score is never misrepresented as a percentage once it exceeds 100.
 */
class PerformanceScoreDisplayTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkflowPermissions();
        Permission::firstOrCreate(['name' => 'view performance', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /**
     * Directly ledgers enough points to push the points-only score above
     * 100, without reconstructing 220 real approvals end-to-end — the ledger
     * is the source of truth finalScore() reads (see workflowPoints()), so
     * inserting rows directly is a faithful stand-in for 220 past approvals.
     */
    private function givePointsOnlyScoreAbove100(User $user, string $period = '2026-10'): void
    {
        for ($i = 0; $i < 220; $i++) {
            PerformancePointEvent::create([
                'user_id' => $user->id, 'event_type' => PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL,
                'source_type' => PerformancePointEvent::SOURCE_SUBMISSION, 'source_id' => $i + 1,
                'points' => 2, 'awarded_at' => Carbon::parse($period.'-15', 'Asia/Dhaka'),
            ]);
        }
    }

    public function test_final_performance_score_can_exceed_100(): void
    {
        $content = $this->user('Content', ['manage raw-content']);
        $this->givePointsOnlyScoreAbove100($content);

        $result = app(PerformanceCalculationService::class)->finalScore($content, '2026-10');

        $this->assertGreaterThan(100, $result['final_score']);
    }

    public function test_kpi_score_is_a_genuine_percentage_kept_separate_from_the_final_score(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->approve($item, $submission, $t['marketing']);

        // Marketing earns a marketing_handover point, with no Task model
        // activity at all — every KPI is inapplicable (points-only branch),
        // so kpi_score is correctly null: there is no weighted KPI blend to
        // show, and final_score is exactly the points contribution.
        $result = app(PerformanceCalculationService::class)->finalScore($t['marketing'], '2026-10');
        $this->assertNull($result['kpi_score']);
        $this->assertSame($result['components']['workflowPoints']['score'], $result['final_score']);
    }

    public function test_the_scorecard_does_not_misleadingly_render_a_score_above_100_as_a_percentage(): void
    {
        $viewer = $this->user('Manager', ['view performance']);
        $content = $this->user('Content', ['manage raw-content']);
        $this->givePointsOnlyScoreAbove100($content);

        $response = $this->actingAs($viewer)->get(route('performance.show', $content).'?period=2026-10');
        $response->assertOk();

        $score = $response->viewData('result')['final_score'];
        $this->assertGreaterThan(100, $score);

        $html = $response->getContent();
        $formatted = number_format($score, 1);
        // The real, uncapped number is rendered...
        $this->assertStringContainsString($formatted, $html);
        // ...but never immediately followed by a "%", the way a genuine percentage would be.
        $this->assertStringNotContainsString($formatted.'%', $html);
        // The three concepts are each labelled, never conflated into one misleading figure.
        $this->assertStringContainsString('KPI Score', $html);
        $this->assertStringContainsString('Workflow Points', $html);
        $this->assertStringContainsString('Performance Score', $html);
    }

    public function test_a_genuine_kpi_percentage_component_still_keeps_its_percent_sign(): void
    {
        // Regression guard: this change touched only final_score's own
        // treatment (the ring + the new breakdown line). An ordinary KPI
        // component — Sales Achievement's "Target achieved" — must still be
        // rendered as the genuine percentage it is, completely untouched.
        $source = file_get_contents(resource_path('views/performance/show.blade.php'));
        $this->assertStringContainsString("\$c['sales']['pct'] !== null ? \$c['sales']['pct'] . '%' : '—'", $source);
    }

    public function test_official_performance_remains_monthly_only(): void
    {
        $viewer = $this->user('Manager', ['view performance']);

        // A non-"Y-m" period (daily/yearly-shaped) silently falls back to the
        // current month — resolvePeriod() never parses a daily or yearly
        // official period; it has no such concept.
        $daily = $this->actingAs($viewer)->get(route('performance.index', ['period' => '2026-10-06']));
        $daily->assertOk();
        $this->assertNotSame('2026-10-06', $daily->viewData('period'));

        $monthly = $this->actingAs($viewer)->get(route('performance.index', ['period' => '2026-10']));
        $monthly->assertOk();
        $this->assertSame('2026-10', $monthly->viewData('period'));
    }

    public function test_claim_alone_awards_no_point(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);
        $this->claimPrePublish($item, $submission, $t['marketing']);

        $this->assertSame(0, PerformancePointEvent::count());
        $this->assertNull(app(PerformanceCalculationService::class)->finalScore($t['marketing'], '2026-10')['final_score']);
    }

    public function test_brand_filtered_point_breakdown_still_works_after_the_ui_changes(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $itemA = $this->newItem($brandA, $t['content']);
        $this->approve($itemA, $this->submitVersion($itemA, $t['content']), $t['marketing']);
        $itemB = $this->newItem($brandB, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $this->approve($itemB, $this->submitVersion($itemB, $t['content']), $t['marketing']);

        $service = app(PerformancePointService::class);
        $period = ReportingPeriod::monthly('2026-10');
        $breakdownA = $service->breakdownForUser($t['content'], $period, $brandA->id);

        $this->assertArrayHasKey(PerformancePointEvent::EVENT_RAW_CONTENT_APPROVAL, $breakdownA);
        $this->assertArrayNotHasKey(PerformancePointEvent::EVENT_ADVERTISING_CONTENT_APPROVAL, $breakdownA);
    }
}
