<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\PublishedContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Manager Oversight activity. Manager is oversight only, so these tests cover
 * reporting and never an approval action. The key guarantee is that Manager
 * and Marketing agree on every shared metric for the same period. The
 * "Unreviewed Published Content" row list follows the selected period (by
 * published_at); only the Department Workload tile above it stays a current,
 * unfiltered backlog count.
 */
class ManagerOversightActivityTest extends TestCase
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

    /** @return array{response: TestResponse, activity: array} */
    private function oversight(User $viewer, array $query = []): array
    {
        $response = $this->actingAs($viewer)->get(route('manager.oversight', $query));
        $response->assertOk();

        return ['response' => $response, 'activity' => $response->viewData('activity')];
    }

    // ── 24–26. Daily / Monthly / Yearly ───────────────────────────────────────

    public function test_daily_filter_counts_only_the_selected_calendar_day(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->oversight($t['manager'], $this->daily('2026-10-06'))['activity']['departments']['marketing']['handed_over']);
        $this->assertSame(0, $this->oversight($t['manager'], $this->daily('2026-10-07'))['activity']['departments']['marketing']['handed_over']);
    }

    public function test_monthly_filter_counts_only_the_selected_calendar_month(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-09-15 10:00', $brand, $t['content']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);

        $this->assertSame(1, $this->oversight($t['manager'], $this->monthly('2026-09'))['activity']['departments']['marketing']['received']);
    }

    public function test_yearly_filter_counts_only_the_selected_calendar_year(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2025-12-31 10:00', $brand, $t['content']);
        $this->submittedAt('2026-06-01 10:00', $brand, $t['content']);

        $this->assertSame(1, $this->oversight($t['manager'], $this->yearly('2026'))['activity']['departments']['marketing']['received']);
    }

    // ── 27. Cross-brand aggregation ───────────────────────────────────────────

    public function test_cross_brand_rows_are_each_brands_own_numbers_and_the_total_is_their_sum(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brandA, $t['content']);
        $this->submittedAt('2026-10-06 10:05', $brandB, $t['content']);
        $this->submittedAt('2026-10-06 10:10', $brandB, $t['content']);

        $brands = $this->oversight($t['manager'], $this->daily('2026-10-06'))['activity']['brands'];

        $this->assertSame(1, $brands['rows'][$brandA->id]['received']);
        $this->assertSame(2, $brands['rows'][$brandB->id]['received']);
        $this->assertSame(3, $brands['totals']['received']);
    }

    // ── 28. Cross-department / category aggregation ───────────────────────────

    public function test_each_department_reports_its_own_category_and_the_marketing_intake_covers_all_three(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['content'], ContentItem::CATEGORY_RAW_CONTENT);
        $this->submittedAt('2026-10-06 10:05', $brand, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $this->submittedAt('2026-10-06 10:10', $brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $departments = $this->oversight($t['manager'], $this->daily('2026-10-06'))['activity']['departments'];

        $this->assertSame(1, $departments['content_raw']['submitted']);
        $this->assertSame(1, $departments['content_advertising']['submitted']);
        $this->assertSame(1, $departments['design']['submitted']);
        $this->assertSame(3, $departments['marketing']['received'], 'Marketing receives every category.');
    }

    // ── 29. Manager / Marketing consistency ───────────────────────────────────

    public function test_manager_and_marketing_report_identical_numbers_for_the_same_period(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);

        // Every shared event type, across two brands, on one day.
        $this->atDhaka('2026-10-06 09:00');
        $revised = $this->newItem($brandA, $t['content']);
        $this->submitVersion($revised, $t['content']);
        $this->reviseItem($revised->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->submittedAt('2026-10-06 09:10', $brandA, $t['content']);
        $this->handedOverAt('2026-10-06 09:20', $brandB, $t['content'], $t['marketing']);
        [, $published] = $this->publishedAt('2026-10-06 09:30', $brandB, $t['content'], $t['marketing'], $t['smm']);
        $this->atDhaka('2026-10-06 10:00');
        $this->reviewPublication($brandB, $published, $t['marketing'])
            ->assertOk();

        $query = $this->daily('2026-10-06');
        $marketing = $this->report($t['marketing'], $query);
        $manager = $this->oversight($t['manager'], $query)['activity'];

        foreach ([$brandA, $brandB] as $brand) {
            $marketingRow = $this->row($marketing, $brand);
            foreach (['received', 'handed_over', 'returned_for_final_check', 'completed', 'revision_requested'] as $metric) {
                $this->assertSame(
                    $marketingRow[$metric],
                    $manager['brands']['rows'][$brand->id][$metric],
                    "Manager and Marketing disagree on {$metric} for {$brand->name}."
                );
            }
        }

        $this->assertSame($marketing['totals']['received'], $manager['departments']['marketing']['received']);
        $this->assertSame($marketing['totals']['handed_over'], $manager['departments']['marketing']['handed_over']);
        $this->assertSame($marketing['totals']['returned_for_final_check'], $manager['departments']['marketing']['returned_for_final_check']);
        $this->assertSame($marketing['totals']['completed'], $manager['departments']['marketing']['completed']);
        $this->assertSame($marketing['totals']['revision_requested'], $manager['departments']['marketing']['revision_requested']);
        $this->assertSame(1, $manager['departments']['marketing']['completed'], 'The one review is a completion for both.');
    }

    // ── 30. The workload tile stays current; the row list follows the period ──

    public function test_the_unreviewed_tile_stays_current_while_the_row_list_follows_the_selected_period(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        [, $published] = $this->publishedAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing'], $t['smm']);

        $september = $this->oversight($t['manager'], $this->monthly('2026-09'));

        $this->assertSame(0, $september['activity']['departments']['marketing']['returned_for_final_check']);
        // The workload tile is a current, unfiltered backlog count — it never hides this.
        $this->assertSame(1, $september['response']->viewData('workload')['marketing']['unreviewed_publishes']);
        // The row list below it now follows the selected period: September — when
        // this wasn't published — excludes it, even though it is still fully
        // actionable today.
        $this->assertFalse($september['response']->viewData('unreviewed')->contains(fn (PublishedContent $p) => $p->id === $published->id));

        // October — when it was actually published — includes it.
        $october = $this->oversight($t['manager'], $this->monthly('2026-10'));
        $this->assertTrue($october['response']->viewData('unreviewed')->contains(fn (PublishedContent $p) => $p->id === $published->id));
    }

    // ── Brand filter: Manager metrics, rows, and Manager/Marketing agreement ──

    public function test_manager_metrics_respect_brand(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brandA, $t['content']);
        $this->submittedAt('2026-10-06 10:05', $brandB, $t['content']);
        $this->submittedAt('2026-10-06 10:10', $brandB, $t['content']);

        $activity = $this->oversight($t['manager'], $this->withBrand($this->daily('2026-10-06'), $brandB))['activity'];

        $this->assertSame(2, $activity['departments']['content_raw']['submitted']);
        $this->assertSame(2, $activity['departments']['marketing']['received']);
    }

    public function test_manager_rows_respect_brand(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        [, $publishedA] = $this->publishedAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing'], $t['smm']);
        [, $publishedB] = $this->publishedAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing'], $t['smm']);

        $result = $this->oversight($t['manager'], $this->withBrand($this->monthly('2026-10'), $brandB));

        $this->assertTrue($result['response']->viewData('unreviewed')->contains(fn (PublishedContent $p) => $p->id === $publishedB->id));
        $this->assertFalse($result['response']->viewData('unreviewed')->contains(fn (PublishedContent $p) => $p->id === $publishedA->id));
    }

    public function test_manager_workload_tile_respects_brand(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $this->publishedAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing'], $t['smm']);
        $this->publishedAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing'], $t['smm']);
        $this->publishedAt('2026-10-06 10:10', $brandB, $t['content'], $t['marketing'], $t['smm']);

        $resultB = $this->oversight($t['manager'], ['brand_id' => $brandB->id]);
        $this->assertSame(2, $resultB['response']->viewData('workload')['marketing']['unreviewed_publishes']);

        $resultA = $this->oversight($t['manager'], ['brand_id' => $brandA->id]);
        $this->assertSame(1, $resultA['response']->viewData('workload')['marketing']['unreviewed_publishes']);
    }

    public function test_manager_and_marketing_definitions_stay_consistent_for_the_same_brand_and_period(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-10-06 09:00', $brandA, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-06 09:05', $brandB, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-06 09:10', $brandB, $t['content'], $t['marketing']);

        $query = $this->withBrand($this->daily('2026-10-06'), $brandB);
        $marketing = $this->report($t['marketing'], $query);
        $manager = $this->oversight($t['manager'], $query)['activity'];

        $this->assertSame(2, $marketing['totals']['handed_over']);
        $this->assertSame($marketing['totals']['handed_over'], $manager['departments']['marketing']['handed_over']);
    }

    /** An unauthorized/invalid brand_id silently falls back to All Brands. */
    public function test_invalid_brand_id_falls_back_to_all_brands_for_manager_oversight(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);

        $query = $this->withBrand($this->monthly('2026-10'), $brand);
        $query['brand_id'] = 999999;
        $this->assertSame(1, $this->oversight($t['manager'], $query)['activity']['departments']['marketing']['received']);
    }

    // ── 31. Asia/Dhaka boundary ───────────────────────────────────────────────

    public function test_the_daily_boundary_follows_asia_dhaka(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 23:30', $brand, $t['content']);
        $this->submittedAt('2026-10-07 00:15', $brand, $t['content']);

        $this->assertSame(1, $this->oversight($t['manager'], $this->daily('2026-10-06'))['activity']['departments']['marketing']['received']);
        $this->assertSame(1, $this->oversight($t['manager'], $this->daily('2026-10-07'))['activity']['departments']['marketing']['received']);
    }
}
