<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PublishedContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Marketing's brand-wise workload reporting. Historical EVENT counts are
 * bounded to a Daily/Monthly/Yearly Asia/Dhaka calendar period and each is
 * timestamped by the business event it counts. CURRENT QUEUE counts are never
 * period-filtered. Every time-sensitive test pins its clock with travelTo(),
 * so the results are deterministic.
 */
class MarketingWorkloadReportingTest extends TestCase
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

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array{content: User, design: User, marketing: User, smm: User, manager: User} */
    private function team(): array
    {
        return $this->workflowTeam();
    }

    public function test_brand_received_count_is_correct_for_the_selected_day(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);
        $this->submittedAt('2026-10-06 15:00', $brand, $t['content']);

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);

        $this->assertSame(2, $row['received']);
    }

    public function test_another_brands_activity_never_changes_this_brands_numbers(): void
    {
        $t = $this->team();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $this->submittedAt('2026-10-06 10:00', $brandA, $t['content']);
        $this->submittedAt('2026-10-06 10:05', $brandB, $t['content']);
        $this->submittedAt('2026-10-06 10:10', $brandB, $t['content']);
        $this->submittedAt('2026-10-06 10:15', $brandB, $t['content']);

        $report = $this->report($t['marketing'], $this->daily('2026-10-06'));

        $this->assertSame(1, $this->row($report, $brandA)['received']);
        $this->assertSame(3, $this->row($report, $brandB)['received']);
    }

    public function test_pending_pre_publish_counts_only_the_current_actionable_latest_version(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->atDhaka('2026-10-06 10:00');
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);
        $this->reviseItem($item->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->submitVersion($item, $t['content']);

        $report = $this->report($t['marketing'], $this->daily('2026-10-06'));
        $row = $this->row($report, $brand);

        $this->assertSame(2, $row['received'], 'Both versions are real historical events.');
        $this->assertSame(1, $row['pending_pre_publish'], 'Only V2 is actionable; V1 was sent back.');
    }

    // ── 4–8. Each metric counted by its own business event ────────────────────

    public function test_marketing_approval_increments_handed_over_for_the_correct_period(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-10-06 11:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand)['handed_over']);
        $this->assertSame(0, $this->row($this->report($t['marketing'], $this->daily('2026-10-07')), $brand)['handed_over']);
    }

    public function test_smm_publication_increments_returned_for_final_check(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->publishedAt('2026-10-06 12:00', $brand, $t['content'], $t['marketing'], $t['smm']);

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);

        $this->assertSame(1, $row['returned_for_final_check']);
    }

    public function test_an_unreviewed_publication_appears_in_pending_final_review(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->publishedAt('2026-10-06 12:00', $brand, $t['content'], $t['marketing'], $t['smm']);

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);

        $this->assertSame(1, $row['pending_final_review']);
        $this->assertSame(0, $row['completed']);
    }

    public function test_marketing_final_review_increments_completed_and_clears_pending_review(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        [, $published] = $this->publishedAt('2026-10-06 12:00', $brand, $t['content'], $t['marketing'], $t['smm']);

        $this->atDhaka('2026-10-06 16:00');
        $this->reviewPublication($brand, $published, $t['marketing'])
            ->assertOk();

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);
        $this->assertSame(1, $row['completed']);
        $this->assertSame(0, $row['pending_final_review']);
    }

    public function test_pre_publish_revisions_are_counted_and_post_publish_revisions_are_not(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);

        // Pre-publish: Marketing sends a waiting submission back.
        $this->atDhaka('2026-10-06 10:00');
        $pre = $this->newItem($brand, $t['content']);
        $this->submitVersion($pre, $t['content']);
        $this->reviseItem($pre->fresh(), ['note' => 'Redo'], $t['marketing']);

        // Post-publish: Marketing sends a published item back. This is not pre-publish activity.
        [$post] = $this->publishedAt('2026-10-06 11:00', $brand, $t['content'], $t['marketing'], $t['smm']);
        $this->reviseItem($post, ['note' => 'Caption typo'], $t['marketing']);

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);

        $this->assertSame(1, $row['revision_requested']);
        $this->assertSame(0, $row['pending_final_review'], 'A publication already sent back is not waiting for final review.');
    }

    // ── 9–10. Event counts vs current queue ───────────────────────────────────

    public function test_v1_and_v2_create_two_historical_received_events_but_one_pending(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->atDhaka('2026-10-06 10:00');
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);
        $this->reviseItem($item->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->submitVersion($item, $t['content']);

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);

        $this->assertSame(2, $row['received']);
        $this->assertSame(1, $row['pending_pre_publish']);
    }

    public function test_a_superseded_revision_requested_v1_does_not_appear_twice_in_the_pending_queue(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);
        $this->reviseItem($item->fresh(), ['note' => 'Redo'], $t['marketing']);
        $this->submitVersion($item, $t['content']);

        $queue = collect($this->actingAs($t['marketing'])->getJson(route('panels.marketing.pending-check'))->json('data'))
            ->where('id', $item->id);

        $this->assertCount(1, $queue);
        $this->assertSame(2, $queue->first()['version']);
    }

    // ── 11–17. Daily, monthly, yearly and Asia/Dhaka boundaries ───────────────

    public function test_daily_filter_counts_only_the_selected_calendar_date(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-10-06 11:00', $brand, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-07 11:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand)['handed_over']);
        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->daily('2026-10-07')), $brand)['handed_over']);
    }

    public function test_monthly_filter_counts_only_the_selected_calendar_month(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-09-15 11:00', $brand, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-10-06 11:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->monthly('2026-09')), $brand)['handed_over']);
        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->monthly('2026-10')), $brand)['handed_over']);
    }

    public function test_yearly_filter_counts_only_the_selected_calendar_year(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2025-12-31 11:00', $brand, $t['content'], $t['marketing']);
        $this->handedOverAt('2026-06-01 11:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->yearly('2026')), $brand)['handed_over']);
        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->yearly('2025')), $brand)['handed_over']);
    }

    public function test_the_daily_boundary_follows_asia_dhaka_not_utc(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        // 2026-10-06 23:30 Dhaka is 17:30 UTC on the 6th: the same day in both zones.
        $this->handedOverAt('2026-10-06 23:30', $brand, $t['content'], $t['marketing']);
        // 2026-10-07 00:15 Dhaka is 18:15 UTC on the 6th: a UTC day filter would wrongly put it on the 6th.
        $this->handedOverAt('2026-10-07 00:15', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand)['handed_over']);
        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->daily('2026-10-07')), $brand)['handed_over']);
    }

    public function test_the_monthly_boundary_follows_asia_dhaka(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-09-30 23:59', $brand, $t['content'], $t['marketing']);
        // Exactly midnight on the 1st Dhaka time belongs to October, never to both months.
        $this->handedOverAt('2026-10-01 00:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->monthly('2026-09')), $brand)['handed_over']);
        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->monthly('2026-10')), $brand)['handed_over']);
    }

    public function test_the_yearly_boundary_follows_asia_dhaka(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-12-31 23:59', $brand, $t['content'], $t['marketing']);
        $this->handedOverAt('2027-01-01 00:00', $brand, $t['content'], $t['marketing']);

        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->yearly('2026')), $brand)['handed_over']);
        $this->assertSame(1, $this->row($this->report($t['marketing'], $this->yearly('2027')), $brand)['handed_over']);
    }

    public function test_invalid_period_input_falls_back_safely_and_never_errors(): void
    {
        $t = $this->team();
        $this->readyBrand($t['manager']);
        $this->atDhaka('2026-10-06 10:00');

        $this->assertSame('monthly', $this->report($t['marketing'], ['period' => 'weekly'])['period']['period']);
        $this->assertSame('2026-10-06', $this->report($t['marketing'], ['period' => 'daily', 'date' => '2026-02-30'])['period']['selected']);
        $this->assertSame('2026-10', $this->report($t['marketing'], ['period' => 'monthly', 'month' => 'abc'])['period']['selected']);
        $this->assertSame('2026', $this->report($t['marketing'], ['period' => 'yearly', 'year' => '2026-99'])['period']['selected']);
    }

    // ── 20. A historical filter never hides the current queue ─────────────────

    public function test_a_historical_filter_never_hides_a_current_october_item_waiting_for_marketing(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $waiting = $this->submittedAt('2026-10-06 10:00', $brand, $t['content']);

        $report = $this->report($t['marketing'], $this->monthly('2026-09'));

        $this->assertSame(1, $this->row($report, $brand)['pending_pre_publish']);
        $this->assertSame(1, $report['totals']['pending_pre_publish']);
        $this->assertContains($waiting->id, collect($this->actingAs($t['marketing'])
            ->getJson(route('panels.marketing.pending-check'))->json('data'))->pluck('id')->all());
    }

    public function test_the_marketing_panel_defaults_to_the_asia_dhaka_calendar_not_the_browser(): void
    {
        $t = $this->team();
        // 2026-10-07 00:30 Dhaka is still 2026-10-06 in UTC; the defaults must name the Dhaka day.
        $this->atDhaka('2026-10-07 00:30');

        $this->actingAs($t['marketing'])->get(route('panels.marketing'))
            ->assertOk()
            ->assertSee('value="2026-10-07"', false)
            ->assertSee('value="2026-10"', false)
            ->assertSee('value="2026"', false);
    }

    // ── 21–22. Access and isolation ───────────────────────────────────────────

    public function test_unauthorized_users_cannot_access_marketing_reporting(): void
    {
        $t = $this->team();
        $this->readyBrand($t['manager']);

        foreach (['content', 'design', 'smm', 'manager'] as $role) {
            $this->actingAs($t[$role])->getJson(route('panels.marketing.workload'))->assertForbidden();
            $this->actingAs($t[$role])->get(route('panels.marketing'))->assertForbidden();
        }
    }

    public function test_cross_brand_isolation_holds_for_every_metric(): void
    {
        $t = $this->team();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $this->handedOverAt('2026-10-06 10:00', $brandB, $t['content'], $t['marketing']);
        $this->submittedAt('2026-10-06 10:05', $brandB, $t['content']);
        $this->publishedAt('2026-10-06 10:10', $brandB, $t['content'], $t['marketing'], $t['smm']);

        $rowA = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brandA);

        foreach (['received', 'pending_pre_publish', 'handed_over', 'returned_for_final_check', 'pending_final_review', 'completed', 'revision_requested'] as $metric) {
            $this->assertSame(0, $rowA[$metric], "Brand B leaked into Brand A's {$metric}.");
        }
    }

    // ── 23–24. Query count does not grow with brand volume ────────────────────

    public function test_query_count_is_the_same_when_more_brands_with_activity_are_added(): void
    {
        $t = $this->team();
        $this->seedBrandsWithActivity($t, 2);
        // The first request also warms the permission cache; measure only warm requests.
        $this->countWorkloadQueries($t['marketing']);
        $before = $this->countWorkloadQueries($t['marketing']);

        $this->seedBrandsWithActivity($t, 4);
        $after = $this->countWorkloadQueries($t['marketing']);

        $this->assertSame($before, $after, 'The brand-wise summary must not run per-brand queries.');
    }

    public function test_the_brand_wise_summary_stays_under_a_fixed_query_budget(): void
    {
        $t = $this->team();
        $this->seedBrandsWithActivity($t, 6);

        $this->assertLessThanOrEqual(30, $this->countWorkloadQueries($t['marketing']));
    }

    /** Six brands each with a handover, a submission, a publication and a pending review, so every metric has rows. */
    private function seedBrandsWithActivity(array $t, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $brand = $this->readyBrand($t['manager']);
            $this->handedOverAt('2026-10-06 09:00', $brand, $t['content'], $t['marketing']);
            $this->submittedAt('2026-10-06 09:30', $brand, $t['content']);
            $this->publishedAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing'], $t['smm']);
        }
    }

    private function countWorkloadQueries(User $viewer): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->getJson(route('panels.marketing.workload', $this->daily('2026-10-06')))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // ── 25–26. Revision/resubmission and historical completion ────────────────

    public function test_revision_and_resubmission_produce_the_correct_metrics(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->atDhaka('2026-10-06 10:00');
        $item = $this->newItem($brand, $t['content']);
        $this->submitVersion($item, $t['content']);
        $this->reviseItem($item->fresh(), ['note' => 'Redo'], $t['marketing']);

        $this->atDhaka('2026-10-06 11:00');
        $v2 = $this->submitVersion($item, $t['content']);

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);
        $this->assertSame(2, $row['received']);
        $this->assertSame(1, $row['revision_requested']);
        $this->assertSame(1, $row['pending_pre_publish']);
        $this->assertSame(0, $row['handed_over']);

        $this->atDhaka('2026-10-06 12:00');
        $this->approve($item, $v2, $t['marketing']);

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);
        $this->assertSame(1, $row['handed_over'], 'Only the exact V2 approval counts; V1 never was handed over.');
        $this->assertSame(0, $row['pending_pre_publish']);
    }

    public function test_a_superseded_version_review_is_not_counted_as_a_completed_cycle(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        [$item, $publishedV1] = $this->publishedAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing'], $t['smm']);
        $this->atDhaka('2026-10-06 10:30');
        $this->submitVersion($item, $t['content']); // a real V2 on the same item, making V1 superseded

        $this->atDhaka('2026-10-06 11:00');
        $this->reviewPublication($brand, $publishedV1, $t['marketing'])
            ->assertOk();

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);
        $this->assertSame(0, $row['completed'], 'Reviewing a superseded V1 does not complete the current cycle.');
    }

    public function test_historical_completed_content_without_an_approval_stays_reportable(): void
    {
        $t = $this->team();
        $brand = $this->readyBrand($t['manager']);
        $this->atDhaka('2026-10-06 10:00');
        $item = $this->newItem($brand, $t['content']);
        $legacy = $this->submitVersion($item, $t['content']);

        // Pre-approval-table history: collected, published and reviewed, with no approval row.
        $item->update(['status' => ContentItem::STATUS_PUBLISHED]);
        $published = PublishedContent::create([
            'content_item_id' => $item->id, 'submission_id' => $legacy->id, 'brand_id' => $brand->id,
            'facebook_post_url' => 'https://facebook.com/legacy', 'published_by' => $t['smm']->id,
            'published_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $t['marketing']->id,
        ]);

        $row = $this->row($this->report($t['marketing'], $this->daily('2026-10-06')), $brand);

        $this->assertSame(1, $row['completed']);
        $this->assertSame(1, $row['returned_for_final_check']);
        $this->assertNotNull($published->fresh()->reviewed_at);
    }
}
