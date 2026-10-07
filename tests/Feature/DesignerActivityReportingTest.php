<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Designer panel activity: poster work only, on the same period and queue
 * rules as Raw Content — the item queue now follows the selected period too.
 */
class DesignerActivityReportingTest extends TestCase
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

    /** @return array<string, mixed> */
    private function activity(User $viewer, array $query = []): array
    {
        $response = $this->actingAs($viewer)->get(route('panels.designer', $query));
        $response->assertOk();

        return $response->viewData('activity');
    }

    /** @return array<int, int> */
    private function queueIds(User $viewer, array $query = []): array
    {
        return collect($this->actingAs($viewer)->getJson(route('panels.designer', $query))->json('data'))->pluck('id')->all();
    }

    private function poster(array $activity): array
    {
        return $activity['categories']['poster'];
    }

    // ── 8–10. Daily / Monthly / Yearly ────────────────────────────────────────

    public function test_daily_filter_counts_only_the_selected_calendar_day(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submittedAt('2026-10-07 10:00', $brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $this->assertSame(1, $this->poster($this->activity($t['design'], $this->daily('2026-10-06')))['submitted']);
    }

    public function test_monthly_filter_counts_only_the_selected_calendar_month(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-09-15 10:00', $brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $this->assertSame(1, $this->poster($this->activity($t['design'], $this->monthly('2026-09')))['submitted']);
    }

    public function test_yearly_filter_counts_only_the_selected_calendar_year(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2025-12-31 10:00', $brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submittedAt('2026-06-01 10:00', $brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $this->assertSame(1, $this->poster($this->activity($t['design'], $this->yearly('2026')))['submitted']);
    }

    // ── 11. Poster-only, category-correct ─────────────────────────────────────

    public function test_designer_counts_are_poster_only(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['content'], ContentItem::CATEGORY_RAW_CONTENT);
        $this->submittedAt('2026-10-06 10:05', $brand, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $this->submittedAt('2026-10-06 10:10', $brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $activity = $this->activity($t['design'], $this->daily('2026-10-06'));

        $this->assertSame(['poster'], array_keys($activity['categories']));
        $this->assertSame(1, $this->poster($activity)['submitted']);
    }

    // ── 12. Revision and resubmission ─────────────────────────────────────────

    public function test_poster_revision_and_resubmission_are_counted_as_resubmitted_work(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->atDhaka('2026-10-06 10:00');
        $item = $this->newItem($brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submitVersion($item, $t['design']);
        $this->reviseItem($item->fresh(), ['note' => 'Logo too small'], $t['marketing']);
        $this->atDhaka('2026-10-06 11:00');
        $this->submitVersion($item, $t['design']);

        $poster = $this->poster($this->activity($t['design'], $this->daily('2026-10-06')));

        $this->assertSame(2, $poster['submitted']);
        $this->assertSame(1, $poster['first_submitted']);
        $this->assertSame(1, $poster['resubmitted']);
        $this->assertSame(1, $poster['revisions_received']);
    }

    // ── 13. The poster queue follows the selected period, even for current work ──

    public function test_the_poster_queue_follows_the_selected_period_even_for_current_work(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $octoberPoster = $this->submittedAt('2026-10-06 10:00', $brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $this->assertSame(0, $this->poster($this->activity($t['design'], $this->monthly('2026-09')))['submitted']);
        // A past period hides it, even though it is still fully actionable today.
        $this->assertNotContains($octoberPoster->id, $this->queueIds($t['design'], $this->monthly('2026-09')));
        // The period it was actually submitted in shows it.
        $this->assertContains($octoberPoster->id, $this->queueIds($t['design'], $this->monthly('2026-10')));
    }

    // ── 14. Asia/Dhaka boundary ───────────────────────────────────────────────

    public function test_the_daily_boundary_follows_asia_dhaka(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 23:30', $brand, $t['design'], ContentItem::CATEGORY_POSTER);
        $this->submittedAt('2026-10-07 00:15', $brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $this->assertSame(1, $this->poster($this->activity($t['design'], $this->daily('2026-10-06')))['submitted']);
        $this->assertSame(1, $this->poster($this->activity($t['design'], $this->daily('2026-10-07')))['submitted']);
    }
}
