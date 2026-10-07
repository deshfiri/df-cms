<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\Reporting\PanelActivityReport;
use App\Support\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Behaviour shared by every activity section: invalid input fallback, calendar
 * rollovers, a bounded query count, and the existing panel permissions.
 */
class ActivityReportingSharedTest extends TestCase
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

    /** Every panel that carries an Activity section, with the user allowed to see it. */
    private function panels(array $t): array
    {
        return [
            'panels.raw-content' => $t['content'],
            'panels.designer' => $t['design'],
            'panels.smm' => $t['smm'],
            'manager.oversight' => $t['manager'],
        ];
    }

    // ── 32. Invalid input falls back safely ───────────────────────────────────

    public function test_invalid_period_values_fall_back_to_the_current_dhaka_period_on_every_panel(): void
    {
        $t = $this->workflowTeam();
        $this->readyBrand($t['manager']);
        $this->atDhaka('2026-10-06 10:00');

        $bad = [
            ['period' => 'weekly'],
            ['period' => 'daily', 'date' => '2026-02-30'],
            ['period' => 'monthly', 'month' => 'abc'],
            ['period' => 'yearly', 'year' => '2026-99'],
        ];

        foreach ($this->panels($t) as $route => $viewer) {
            foreach ($bad as $query) {
                $response = $this->actingAs($viewer)->get(route($route, $query));
                $response->assertOk();
                $this->assertInstanceOf(ReportingPeriod::class, $response->viewData('period'));
                $this->assertContains($response->viewData('period')->period, ['daily', 'monthly', 'yearly']);
            }
        }

        $this->assertSame('monthly', $this->actingAs($t['content'])->get(route('panels.raw-content', ['period' => 'weekly']))->viewData('period')->period);
        $this->assertSame('2026-10-06', $this->actingAs($t['content'])->get(route('panels.raw-content', ['period' => 'daily', 'date' => '2026-02-30']))->viewData('period')->selected);
    }

    // ── 33–34. Month and year rollovers ───────────────────────────────────────

    public function test_month_rollover_places_the_last_second_of_december_and_the_first_of_january_in_different_months(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-12-31 23:59', $brand, $t['content']);
        $this->submittedAt('2027-01-01 00:00', $brand, $t['content']);

        $december = $this->actingAs($t['content'])->get(route('panels.raw-content', $this->monthly('2026-12')))->viewData('activity');
        $january = $this->actingAs($t['content'])->get(route('panels.raw-content', $this->monthly('2027-01')))->viewData('activity');

        $this->assertSame(1, $december['categories']['raw_content']['submitted']);
        $this->assertSame(1, $january['categories']['raw_content']['submitted']);
    }

    public function test_year_rollover_places_the_last_second_of_2026_and_the_first_of_2027_in_different_years(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-12-31 23:59', $brand, $t['content'], $t['marketing']);
        $this->handedOverAt('2027-01-01 00:00', $brand, $t['content'], $t['marketing']);

        $y2026 = $this->actingAs($t['smm'])->get(route('panels.smm', $this->yearly('2026')))->viewData('activity');
        $y2027 = $this->actingAs($t['smm'])->get(route('panels.smm', $this->yearly('2027')))->viewData('activity');

        $this->assertSame(1, $y2026['received']);
        $this->assertSame(1, $y2027['received']);
    }

    // ── 35. Query count stays bounded ─────────────────────────────────────────

    public function test_the_activity_query_count_does_not_grow_when_brands_and_items_are_added(): void
    {
        $t = $this->workflowTeam();
        $this->seedActivityBrands($t, 2);
        $before = $this->countActivityQueries($t);

        $this->seedActivityBrands($t, 4);
        $after = $this->countActivityQueries($t);

        $this->assertSame($before, $after, 'The activity sections must not run per-brand or per-item queries.');
        $this->assertLessThanOrEqual(60, $after);
    }

    /** Brands with each kind of event so every metric has rows. */
    private function seedActivityBrands(array $t, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $brand = $this->readyBrand($t['manager']);
            $this->handedOverAt('2026-10-06 09:00', $brand, $t['content'], $t['marketing']);
            $this->submittedAt('2026-10-06 09:30', $brand, $t['design'], ContentItem::CATEGORY_POSTER);
            $this->publishedAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing'], $t['smm']);
        }
    }

    private function countActivityQueries(array $t): int
    {
        $period = ReportingPeriod::daily('2026-10-06');
        $brands = Brand::whereHas('checklist')->orderBy('name')->get(['id', 'name']);
        $activity = app(PanelActivityReport::class);

        // The first pass warms the role and permission caches; only warm passes are compared.
        $activity->rawContent($period);
        $activity->managerOversight($period, $brands);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $activity->rawContent($period);
        $activity->designer($period);
        $activity->smm($period);
        $activity->managerOversight($period, $brands);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // ── 36. Existing panel permissions still block access ─────────────────────

    public function test_unauthorized_users_cannot_open_any_activity_panel_or_its_data(): void
    {
        $t = $this->workflowTeam();
        $this->readyBrand($t['manager']);

        $denied = [
            ['panels.raw-content', $t['design']], ['panels.raw-content', $t['smm']], ['panels.raw-content', $t['manager']],
            ['panels.designer', $t['content']], ['panels.designer', $t['smm']], ['panels.designer', $t['manager']],
            ['panels.smm', $t['content']], ['panels.smm', $t['design']], ['panels.smm', $t['manager']],
            ['manager.oversight', $t['content']], ['manager.oversight', $t['design']], ['manager.oversight', $t['smm']],
        ];

        foreach ($denied as [$route, $user]) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
            $this->actingAs($user)->get(route($route, $this->monthly('2026-09')))->assertForbidden();
        }
    }
}
