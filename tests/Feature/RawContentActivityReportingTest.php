<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Raw Content panel activity. The Activity section follows the selected
 * Daily/Monthly/Yearly period, and the current queue never does. Category
 * isolation is explicit: raw_content and advertising_content are separate rows,
 * and the combined row is labelled as combined.
 */
class RawContentActivityReportingTest extends TestCase
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
        $response = $this->actingAs($viewer)->get(route('panels.raw-content', $query));
        $response->assertOk();

        return $response->viewData('activity');
    }

    /** @return array<int, int> */
    private function queueIds(User $viewer): array
    {
        return collect($this->actingAs($viewer)->getJson(route('panels.raw-content'))->json('data'))->pluck('id')->all();
    }

    // ── 1–3. Daily / Monthly / Yearly ─────────────────────────────────────────

    public function test_daily_filter_counts_only_the_selected_calendar_day(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);
        $this->submittedAt('2026-10-07 10:00', $brand, $t['content']);

        $this->assertSame(1, $this->activity($t['content'], $this->daily('2026-10-06'))['categories']['raw_content']['submitted']);
        $this->assertSame(1, $this->activity($t['content'], $this->daily('2026-10-07'))['categories']['raw_content']['submitted']);
    }

    public function test_monthly_filter_counts_only_the_selected_calendar_month(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-09-15 10:00', $brand, $t['content']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);

        $this->assertSame(1, $this->activity($t['content'], $this->monthly('2026-09'))['categories']['raw_content']['submitted']);
        $this->assertSame(1, $this->activity($t['content'], $this->monthly('2026-10'))['categories']['raw_content']['submitted']);
    }

    public function test_yearly_filter_counts_only_the_selected_calendar_year(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2025-12-31 10:00', $brand, $t['content']);
        $this->submittedAt('2026-06-01 10:00', $brand, $t['content']);

        $this->assertSame(1, $this->activity($t['content'], $this->yearly('2026'))['categories']['raw_content']['submitted']);
    }

    // ── 4. Category isolation ─────────────────────────────────────────────────

    public function test_raw_content_rows_never_include_advertising_or_poster_work(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['content'], ContentItem::CATEGORY_RAW_CONTENT);
        $this->submittedAt('2026-10-06 10:05', $brand, $t['content'], ContentItem::CATEGORY_ADVERTISING_CONTENT);
        $this->submittedAt('2026-10-06 10:10', $brand, $t['design'], ContentItem::CATEGORY_POSTER);

        $activity = $this->activity($t['content'], $this->daily('2026-10-06'));

        $this->assertSame(['raw_content', 'advertising_content'], array_keys($activity['categories']));
        $this->assertSame(1, $activity['categories']['raw_content']['submitted']);
        $this->assertSame(1, $activity['categories']['advertising_content']['submitted']);
        $this->assertSame(2, $activity['combined']['submitted'], 'The combined row is labelled and is the only place both categories meet.');
    }

    // ── 5. V1 / V2 history ────────────────────────────────────────────────────

    public function test_v1_revision_and_v2_show_the_full_history_without_a_stale_queue_entry(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->atDhaka('2026-10-06 10:00');
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);
        $this->reviseItem($item->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->atDhaka('2026-10-06 10:30');
        $this->submitVersion($item, $t['content']);

        $raw = $this->activity($t['content'], $this->daily('2026-10-06'))['categories']['raw_content'];

        $this->assertSame(2, $raw['submitted'], 'Both versions are historical events.');
        $this->assertSame(1, $raw['first_submitted']);
        $this->assertSame(1, $raw['resubmitted']);
        $this->assertSame(1, $raw['revisions_received']);
        // The queue shows the item once, as its current version. V1 is not an active entry.
        $this->assertSame(1, collect($this->queueIds($t['content']))->filter(fn ($id) => $id === $item->id)->count());
    }

    // ── 6. Current queue stays accessible in a historical period ──────────────

    public function test_the_current_queue_stays_accessible_while_a_historical_period_is_selected(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $urgent = $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);

        $september = $this->activity($t['content'], $this->monthly('2026-09'));

        $this->assertSame(0, $september['categories']['raw_content']['submitted']);
        $this->assertContains($urgent->id, $this->queueIds($t['content']));
    }

    // ── 7. Asia/Dhaka boundary ────────────────────────────────────────────────

    public function test_the_daily_boundary_follows_asia_dhaka(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 23:30', $brand, $t['content']);
        // 00:15 Dhaka is 18:15 UTC on the 6th, but it belongs to the 7th.
        $this->submittedAt('2026-10-07 00:15', $brand, $t['content']);

        $this->assertSame(1, $this->activity($t['content'], $this->daily('2026-10-06'))['categories']['raw_content']['submitted']);
        $this->assertSame(1, $this->activity($t['content'], $this->daily('2026-10-07'))['categories']['raw_content']['submitted']);
    }

    // ── Completed uses Marketing's final review of the current version ────────

    public function test_completed_counts_marketing_final_reviews_of_the_current_version(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        [, $published] = $this->publishedAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing'], $t['smm']);

        $this->atDhaka('2026-10-06 12:00');
        $this->reviewPublication($brand, $published, $t['marketing'])
            ->assertOk();

        $this->assertSame(1, $this->activity($t['content'], $this->daily('2026-10-06'))['categories']['raw_content']['completed']);
        $this->assertSame(0, $this->activity($t['content'], $this->daily('2026-10-07'))['categories']['raw_content']['completed']);
    }
}
