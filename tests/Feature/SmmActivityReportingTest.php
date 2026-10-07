<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * SMM panel activity. Every category counts, because SMM receives approved
 * work from all three makers. "Received" is Marketing's approval time, never
 * the submission time, so each metric uses its own event timestamp. The
 * Available/Collected/Published row lists follow the selected period too,
 * each by its own canonical timestamp (approved_at/collected_at/published_at).
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
    private function queueIds(User $viewer, string $route, array $query = []): array
    {
        return collect($this->actingAs($viewer)->getJson(route($route, $query))->json('data'))->pluck('id')->all();
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

    // ── 22. Available / Collected queues follow the selected period ───────────

    public function test_the_available_and_collected_queues_follow_the_selected_period_even_for_current_work(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $waiting = $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);
        $collected = $this->handedOverAt('2026-10-06 10:05', $brand, $t['content'], $t['marketing']);
        $this->collect($collected, $t['smm']);

        $this->assertSame(0, $this->activity($t['smm'], $this->monthly('2026-09'))['received']);

        // September — the row's handover/collection didn't happen then — hides both.
        $this->assertNotContains($waiting->id, $this->queueIds($t['smm'], 'panels.smm.available', $this->monthly('2026-09')));
        $this->assertNotContains($collected->id, $this->queueIds($t['smm'], 'panels.smm.collected', $this->monthly('2026-09')));

        // October — when the handover/collection actually happened — shows both.
        $this->assertContains($waiting->id, $this->queueIds($t['smm'], 'panels.smm.available', $this->monthly('2026-10')));
        $this->assertContains($collected->id, $this->queueIds($t['smm'], 'panels.smm.collected', $this->monthly('2026-10')));
    }

    /**
     * The exact browser scenario this fix was written for: a poster handed
     * over to SMM in October. Selecting February must show zero activity AND
     * must not render this brand/product/title in the Available tab;
     * selecting October must render it.
     */
    public function test_an_october_handover_is_hidden_from_available_in_february_and_shown_in_october(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $brand->update(['name' => 'Test brand']);
        $product = Product::create(['brand_id' => $brand->id, 'name' => 'Education board', 'created_by' => 1]);

        $this->atDhaka('2026-10-02 10:00');
        $item = $this->service()->create($brand, [
            'category' => ContentItem::CATEGORY_POSTER, 'title' => 'Poster', 'product_id' => $product->id,
        ], $t['design']);
        $submission = $this->submitVersion($item, $t['design']);
        $this->approve($item, $submission, $t['marketing']);

        $februaryActivity = $this->activity($t['smm'], $this->monthly('2026-02'));
        $this->assertSame(0, $februaryActivity['received'], 'Received=0/Collected=0/Published=0 in February, as the metrics already correctly show.');
        $this->assertSame(0, $februaryActivity['collected']);
        $this->assertSame(0, $februaryActivity['published']);
        $this->assertSame(0, $februaryActivity['revision_requested']);

        $february = $this->actingAs($t['smm'])->getJson(route('panels.smm.available', $this->monthly('2026-02')));
        $february->assertOk();
        $this->assertStringNotContainsString('Test brand', $february->getContent(), 'February must not render the October handover row.');
        $this->assertStringNotContainsString('Education board', $february->getContent());

        $october = $this->actingAs($t['smm'])->getJson(route('panels.smm.available', $this->monthly('2026-10')));
        $october->assertOk();
        $this->assertStringContainsString('Test brand', $october->getContent(), 'October — the period it was actually handed over in — must render it.');
        $this->assertStringContainsString('Education board', $october->getContent());
    }

    /**
     * Available must use the handover (approval) timestamp, never the
     * original submission's created_at. Submitted September 30, handed over
     * October 2: September must not show it (that would be using the wrong
     * timestamp), October must.
     */
    public function test_available_uses_the_handover_timestamp_not_the_original_submission_timestamp(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);

        $this->atDhaka('2026-09-30 23:00');
        $item = $this->newItem($brand, $t['content']);
        $submission = $this->submitVersion($item, $t['content']);

        $this->atDhaka('2026-10-02 09:00');
        $this->approve($item, $submission, $t['marketing']);

        $this->assertNotContains($item->id, $this->queueIds($t['smm'], 'panels.smm.available', $this->monthly('2026-09')));
        $this->assertContains($item->id, $this->queueIds($t['smm'], 'panels.smm.available', $this->monthly('2026-10')));
    }

    /** The Published row list follows the selected period too, by published_at. */
    public function test_the_published_queue_follows_the_selected_period(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        [, $septPublished] = $this->publishedAt('2026-09-20 10:00', $brand, $t['content'], $t['marketing'], $t['smm']);
        [, $octPublished] = $this->publishedAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing'], $t['smm']);

        $septIds = $this->queueIds($t['smm'], 'panels.smm.published', $this->monthly('2026-09'));
        $octIds = $this->queueIds($t['smm'], 'panels.smm.published', $this->monthly('2026-10'));

        $this->assertContains($septPublished->id, $septIds);
        $this->assertNotContains($octPublished->id, $septIds);
        $this->assertContains($octPublished->id, $octIds);
        $this->assertNotContains($septPublished->id, $octIds);
    }

    /** Section 21: Daily and Yearly must behave the same way, not just Monthly. */
    public function test_the_available_queue_follows_daily_and_yearly_periods_too(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $oct6 = $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);
        $oct7 = $this->handedOverAt('2026-10-07 10:00', $brand, $t['content'], $t['marketing']);
        $y2025 = $this->handedOverAt('2025-05-01 10:00', $brand, $t['content'], $t['marketing']);

        // Daily: the 6th and the 7th don't show each other's row.
        $day6 = $this->queueIds($t['smm'], 'panels.smm.available', $this->daily('2026-10-06'));
        $day7 = $this->queueIds($t['smm'], 'panels.smm.available', $this->daily('2026-10-07'));
        $this->assertContains($oct6->id, $day6);
        $this->assertNotContains($oct7->id, $day6);
        $this->assertContains($oct7->id, $day7);
        $this->assertNotContains($oct6->id, $day7);

        // Yearly: 2025 and 2026 don't show each other's row.
        $year2025 = $this->queueIds($t['smm'], 'panels.smm.available', $this->yearly('2025'));
        $year2026 = $this->queueIds($t['smm'], 'panels.smm.available', $this->yearly('2026'));
        $this->assertContains($y2025->id, $year2025);
        $this->assertNotContains($oct6->id, $year2025);
        $this->assertContains($oct6->id, $year2026);
        $this->assertContains($oct7->id, $year2026);
        $this->assertNotContains($y2025->id, $year2026);
    }

    // ── Brand filter: Available / Collected / Published / activity metrics ────

    public function test_available_respects_brand(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $itemA = $this->handedOverAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing']);
        $itemB = $this->handedOverAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing']);

        $ids = $this->queueIds($t['smm'], 'panels.smm.available', $this->withBrand($this->monthly('2026-10'), $brandA));

        $this->assertContains($itemA->id, $ids);
        $this->assertNotContains($itemB->id, $ids);
    }

    public function test_collected_respects_brand(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $itemA = $this->handedOverAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing']);
        $itemB = $this->handedOverAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing']);
        $this->collect($itemA, $t['smm']);
        $this->collect($itemB, $t['smm']);

        $ids = $this->queueIds($t['smm'], 'panels.smm.collected', $this->withBrand($this->monthly('2026-10'), $brandB));

        $this->assertContains($itemB->id, $ids);
        $this->assertNotContains($itemA->id, $ids);
    }

    public function test_published_respects_brand(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        [, $pubA] = $this->publishedAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing'], $t['smm']);
        [, $pubB] = $this->publishedAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing'], $t['smm']);

        $ids = $this->queueIds($t['smm'], 'panels.smm.published', $this->withBrand($this->monthly('2026-10'), $brandA));

        $this->assertContains($pubA->id, $ids);
        $this->assertNotContains($pubB->id, $ids);
    }

    public function test_activity_metrics_respect_brand(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-06 10:10', $brandB, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->activity($t['smm'], $this->withBrand($this->monthly('2026-10'), $brandA))['received']);
        $this->assertSame(2, $this->activity($t['smm'], $this->withBrand($this->monthly('2026-10'), $brandB))['received']);
        $this->assertSame(3, $this->activity($t['smm'], $this->monthly('2026-10'))['received'], 'All Brands.');
    }

    public function test_period_and_brand_combine_correctly_for_available(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $febA = $this->handedOverAt('2026-02-06 10:00', $brandA, $t['content'], $t['marketing']);
        $octA = $this->handedOverAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing']);
        $octB = $this->handedOverAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing']);

        $febBrandA = $this->queueIds($t['smm'], 'panels.smm.available', $this->withBrand($this->monthly('2026-02'), $brandA));
        $octBrandA = $this->queueIds($t['smm'], 'panels.smm.available', $this->withBrand($this->monthly('2026-10'), $brandA));

        $this->assertContains($febA->id, $febBrandA);
        $this->assertNotContains($octA->id, $febBrandA);
        $this->assertContains($octA->id, $octBrandA);
        $this->assertNotContains($octB->id, $octBrandA);
    }

    /**
     * Section 24's exact regression scenario: an October Brand A item and an
     * October Brand B item in Available. Brand A shows only A, Brand B shows
     * only B, and February + Brand A shows neither — hitting the real
     * endpoint's rendered JSON, not just ids.
     */
    public function test_october_brand_a_and_brand_b_available_items_are_mutually_exclusive(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandA->update(['name' => 'Brand Alpha']);
        $brandB = $this->readyBrand($t['manager']);
        $brandB->update(['name' => 'Brand Beta']);
        $this->handedOverAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing']);

        $octA = $this->actingAs($t['smm'])->getJson(route('panels.smm.available', $this->withBrand($this->monthly('2026-10'), $brandA)));
        $octA->assertOk();
        $this->assertStringContainsString('Brand Alpha', $octA->getContent());
        $this->assertStringNotContainsString('Brand Beta', $octA->getContent());

        $octB = $this->actingAs($t['smm'])->getJson(route('panels.smm.available', $this->withBrand($this->monthly('2026-10'), $brandB)));
        $octB->assertOk();
        $this->assertStringContainsString('Brand Beta', $octB->getContent());
        $this->assertStringNotContainsString('Brand Alpha', $octB->getContent());

        $febA = $this->actingAs($t['smm'])->getJson(route('panels.smm.available', $this->withBrand($this->monthly('2026-02'), $brandA)));
        $febA->assertOk();
        $this->assertStringNotContainsString('Brand Alpha', $febA->getContent());
        $this->assertStringNotContainsString('Brand Beta', $febA->getContent());
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
