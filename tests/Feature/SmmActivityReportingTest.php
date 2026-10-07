<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * SMM panel activity. Every category counts, because SMM receives approved
 * work from all three makers. "Received" is Marketing's approval time, never
 * the submission time, so each metric uses its own event timestamp.
 */
class SmmActivityReportingTest extends TestCase
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

    /** @return array<string, int> */
    private function activity(User $viewer, array $query = []): array
    {
        $response = $this->actingAs($viewer)->get(route('panels.smm', $query));
        $response->assertOk();

        return $response->viewData('activity');
    }

    /** @return array<int, int> */
    private function queueIds(User $viewer, string $route): array
    {
        return collect($this->actingAs($viewer)->getJson(route($route))->json('data'))->pluck('id')->all();
    }

    // ── 15–17. Daily / Monthly / Yearly ───────────────────────────────────────

    public function test_daily_filter_counts_only_the_selected_calendar_day(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->activity($t['smm'], $this->daily('2026-10-06'))['received']);
        $this->assertSame(0, $this->activity($t['smm'], $this->daily('2026-10-07'))['received']);
    }

    public function test_monthly_filter_counts_only_the_selected_calendar_month(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-09-15 10:00', $brand, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->activity($t['smm'], $this->monthly('2026-09'))['received']);
        $this->assertSame(1, $this->activity($t['smm'], $this->monthly('2026-10'))['received']);
    }

    public function test_yearly_filter_counts_only_the_selected_calendar_year(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2025-12-31 10:00', $brand, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-06-01 10:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->activity($t['smm'], $this->yearly('2026'))['received']);
    }

    // ── 18. Received uses the Marketing approval timestamp ────────────────────

    public function test_received_uses_the_marketing_approval_time_not_the_submission_time(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->submittedAt('2026-10-05 10:00', $brand, $t['content']);

        $this->atDhaka('2026-10-06 10:00');
        $this->approve($item, $item->latestSubmission(), $t['marketing']);

        $this->assertSame(0, $this->activity($t['smm'], $this->daily('2026-10-05'))['received'], 'Submitted but not yet handed over.');
        $this->assertSame(1, $this->activity($t['smm'], $this->daily('2026-10-06'))['received']);
    }

    // ── 19. Collected uses the collection timestamp ───────────────────────────

    public function test_collected_uses_the_collection_time(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->handedOverAt('2026-10-05 10:00', $brand, $t['content'], $t['marketing']);

        $this->atDhaka('2026-10-06 10:00');
        $this->collect($item, $t['smm']);

        $this->assertSame(0, $this->activity($t['smm'], $this->daily('2026-10-05'))['collected']);
        $this->assertSame(1, $this->activity($t['smm'], $this->daily('2026-10-06'))['collected']);
    }

    // ── 20. Published uses the publication timestamp ──────────────────────────

    public function test_published_uses_the_publication_time(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->handedOverAt('2026-10-05 10:00', $brand, $t['content'], $t['marketing']);
        $this->collect($item, $t['smm']);

        $this->atDhaka('2026-10-06 10:00');
        $this->publish($item, $brand, $item->latestSubmission(), $t['smm']);

        $this->assertSame(0, $this->activity($t['smm'], $this->daily('2026-10-05'))['published']);
        $this->assertSame(1, $this->activity($t['smm'], $this->daily('2026-10-06'))['published']);
    }

    // ── 21. SMM revisions exclude Marketing's revisions ───────────────────────

    public function test_smm_revision_count_excludes_marketing_pre_publish_revisions(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);

        // Marketing sends a waiting submission back. This is not an SMM revision.
        $this->atDhaka('2026-10-06 10:00');
        $waiting = $this->newItem($brand, $t['content']);
        $this->submitVersion($waiting, $t['content']);
        $this->reviseItem($waiting->fresh(), ['note' => 'Redo'], $t['marketing']);

        // SMM sends back an item it had collected. This is an SMM revision.
        $collected = $this->handedOverAt('2026-10-06 10:30', $brand, $t['content'], $t['marketing']);
        $this->collect($collected, $t['smm']);
        $this->reviseItem($collected->fresh(), ['note' => 'Wrong size'], $t['smm']);

        $this->assertSame(1, $this->activity($t['smm'], $this->daily('2026-10-06'))['revision_requested']);
    }

    // ── 22. Current Available / Collected queues stay accessible ──────────────

    public function test_the_current_available_and_collected_queues_stay_accessible_during_a_historical_period(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $waiting = $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);
        $collected = $this->handedOverAt('2026-10-06 10:05', $brand, $t['content'], $t['marketing']);
        $this->collect($collected, $t['smm']);

        $this->assertSame(0, $this->activity($t['smm'], $this->monthly('2026-09'))['received']);
        $this->assertContains($waiting->id, $this->queueIds($t['smm'], 'panels.smm.available'));
        $this->assertContains($collected->id, $this->queueIds($t['smm'], 'panels.smm.collected'));
    }

    // ── 23. Asia/Dhaka boundary ───────────────────────────────────────────────

    public function test_the_daily_boundary_follows_asia_dhaka(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-10-06 23:30', $brand, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-07 00:15', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->activity($t['smm'], $this->daily('2026-10-06'))['received']);
        $this->assertSame(1, $this->activity($t['smm'], $this->daily('2026-10-07'))['received']);
    }
}
