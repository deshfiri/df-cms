<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\SmmClientConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Marketing's brand-wise SMM Client Conversation reporting (Final Gap
 * section 7) — PanelController::marketingConversationsSummary() and
 * PanelActivityReport::conversationsBrands(). Submitted/Approved/Rejected are
 * timestamped by their own event (submitted_at/reviewed_at); Pending Review
 * is current, period-unfiltered work that still respects the selected Brand
 * (period and brand are independent filters — see BrandScope, fd37c97).
 * Reuses the exact same brand_id/ReportingPeriod infrastructure as every
 * other panel; no second filtering implementation.
 */
class MarketingConversationReportingTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkflowPermissions();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function smm(): User
    {
        return $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content', 'view smm-panel']);
    }

    private function log(User $smm, Brand $brand): SmmClientConversation
    {
        $response = $this->actingAs($smm)->postJson(route('smm-conversations.store'), [
            'brand_id' => $brand->id,
            'reference' => 'Thread '.uniqid(),
            'idempotency_key' => 'key-'.uniqid('', true),
            'evidence' => UploadedFile::fake()->image('s.png', 200, 200),
        ]);
        $response->assertCreated();

        return SmmClientConversation::findOrFail($response->json('data.id'));
    }

    private function decide(User $marketing, SmmClientConversation $c, string $decision): void
    {
        $this->actingAs($marketing)->postJson(route('smm-conversations.decide', $c), ['decision' => $decision])->assertOk();
    }

    private function summary(User $marketing, array $query = []): array
    {
        $response = $this->actingAs($marketing)->getJson(route('panels.marketing.conversations-summary', $query));
        $response->assertOk();

        return $response->json();
    }

    private function row(array $summary, Brand $brand): array
    {
        return collect($summary['data'])->firstWhere('brand_id', $brand->id);
    }

    /** Brand A: 5 submitted (2 approved, 1 rejected, 2 pending). Brand B: 3 submitted (1 approved, 1 rejected, 1 pending). All in October. */
    private function asymmetricFixture(): array
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brandA = $this->readyBrand($manager);
        $brandB = $this->readyBrand($manager);

        $this->atDhaka('2026-10-06 10:00');
        $aRecords = [];
        for ($i = 0; $i < 5; $i++) {
            $aRecords[] = $this->log($smm, $brandA);
        }
        $bRecords = [];
        for ($i = 0; $i < 3; $i++) {
            $bRecords[] = $this->log($smm, $brandB);
        }

        $this->atDhaka('2026-10-07 10:00');
        $this->decide($marketing, $aRecords[0], 'approved');
        $this->decide($marketing, $aRecords[1], 'approved');
        $this->decide($marketing, $aRecords[2], 'rejected');
        $this->decide($marketing, $bRecords[0], 'approved');
        $this->decide($marketing, $bRecords[1], 'rejected');
        // Remaining unreviewed: Brand A has 2 pending, Brand B has 1 pending.

        return compact('manager', 'marketing', 'smm', 'brandA', 'brandB');
    }

    // ── 18–20. All Brands / Brand A / Brand B ───────────────────────────────

    public function test_all_brands_summary_is_correct(): void
    {
        $f = $this->asymmetricFixture();

        $summary = $this->summary($f['marketing'], $this->monthly('2026-10'));

        $this->assertSame(8, $summary['totals']['submitted']);
        $this->assertSame(3, $summary['totals']['pending_review']);
        $this->assertSame(3, $summary['totals']['approved']);
        $this->assertSame(2, $summary['totals']['rejected']);
    }

    public function test_brand_a_summary_excludes_brand_b(): void
    {
        $f = $this->asymmetricFixture();

        $summary = $this->summary($f['marketing'], $this->withBrand($this->monthly('2026-10'), $f['brandA']));

        $this->assertCount(1, $summary['data']);
        $row = $this->row($summary, $f['brandA']);
        $this->assertSame(5, $row['submitted']);
        $this->assertSame(2, $row['pending_review']);
        $this->assertSame(2, $row['approved']);
        $this->assertSame(1, $row['rejected']);
    }

    public function test_brand_b_summary_excludes_brand_a(): void
    {
        $f = $this->asymmetricFixture();

        $summary = $this->summary($f['marketing'], $this->withBrand($this->monthly('2026-10'), $f['brandB']));

        $this->assertCount(1, $summary['data']);
        $row = $this->row($summary, $f['brandB']);
        $this->assertSame(3, $row['submitted']);
        $this->assertSame(1, $row['pending_review']);
        $this->assertSame(1, $row['approved']);
        $this->assertSame(1, $row['rejected']);
    }

    // ── 21–24. Canonical event timestamps ───────────────────────────────────

    public function test_submitted_uses_submitted_at(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brand = $this->readyBrand($manager);

        $this->atDhaka('2026-09-15 10:00');
        $this->log($smm, $brand);
        $this->atDhaka('2026-10-06 10:00');
        $this->log($smm, $brand);

        $sept = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-09'), $brand)), $brand);
        $oct = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-10'), $brand)), $brand);
        $this->assertSame(1, $sept['submitted']);
        $this->assertSame(1, $oct['submitted']);
    }

    public function test_approved_uses_reviewed_at_not_submitted_at(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brand = $this->readyBrand($manager);

        $this->atDhaka('2026-09-15 10:00');
        $c = $this->log($smm, $brand);
        $this->atDhaka('2026-10-06 10:00');
        $this->decide($marketing, $c, 'approved');

        $sept = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-09'), $brand)), $brand);
        $oct = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-10'), $brand)), $brand);
        $this->assertSame(0, $sept['approved'], 'Submitted in September but reviewed in October.');
        $this->assertSame(1, $oct['approved']);
    }

    public function test_rejected_uses_reviewed_at_not_submitted_at(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brand = $this->readyBrand($manager);

        $this->atDhaka('2026-09-15 10:00');
        $c = $this->log($smm, $brand);
        $this->atDhaka('2026-10-06 10:00');
        $this->decide($marketing, $c, 'rejected');

        $sept = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-09'), $brand)), $brand);
        $oct = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-10'), $brand)), $brand);
        $this->assertSame(0, $sept['rejected']);
        $this->assertSame(1, $oct['rejected']);
    }

    public function test_pending_uses_current_state_not_period(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brand = $this->readyBrand($manager);

        $this->atDhaka('2026-09-15 10:00');
        $this->log($smm, $brand); // still pending today

        $sept = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-09'), $brand)), $brand);
        $oct = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-10'), $brand)), $brand);
        $this->assertSame(1, $sept['pending_review'], 'Current pending work is visible from a historical period filter too.');
        $this->assertSame(1, $oct['pending_review']);
    }

    // ── 25–29. Daily / Monthly / Yearly, combination, Asia/Dhaka ────────────

    public function test_daily_filter_is_correct(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brand = $this->readyBrand($manager);

        $this->atDhaka('2026-10-06 10:00');
        $this->log($smm, $brand);
        $this->atDhaka('2026-10-07 10:00');
        $this->log($smm, $brand);

        $day6 = $this->row($this->summary($marketing, $this->withBrand($this->daily('2026-10-06'), $brand)), $brand);
        $this->assertSame(1, $day6['submitted']);
    }

    public function test_monthly_filter_is_correct(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brand = $this->readyBrand($manager);

        $this->atDhaka('2026-09-15 10:00');
        $this->log($smm, $brand);
        $this->atDhaka('2026-10-06 10:00');
        $this->log($smm, $brand);

        $sept = $this->row($this->summary($marketing, $this->withBrand($this->monthly('2026-09'), $brand)), $brand);
        $this->assertSame(1, $sept['submitted']);
    }

    public function test_yearly_filter_is_correct(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brand = $this->readyBrand($manager);

        $this->atDhaka('2025-12-31 10:00');
        $this->log($smm, $brand);
        $this->atDhaka('2026-06-01 10:00');
        $this->log($smm, $brand);

        $y2026 = $this->row($this->summary($marketing, $this->withBrand($this->yearly('2026'), $brand)), $brand);
        $this->assertSame(1, $y2026['submitted']);
    }

    public function test_period_and_brand_combination_is_correct(): void
    {
        $f = $this->asymmetricFixture();

        $febA = $this->summary($f['marketing'], $this->withBrand($this->monthly('2026-02'), $f['brandA']));
        $this->assertSame(0, $febA['totals']['submitted'], 'Nothing happened for Brand A in February.');
    }

    public function test_the_daily_boundary_follows_asia_dhaka(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $brand = $this->readyBrand($manager);

        $this->atDhaka('2026-10-06 23:30');
        $this->log($smm, $brand);
        $this->atDhaka('2026-10-07 00:15');
        $this->log($smm, $brand);

        $day6 = $this->row($this->summary($marketing, $this->withBrand($this->daily('2026-10-06'), $brand)), $brand);
        $day7 = $this->row($this->summary($marketing, $this->withBrand($this->daily('2026-10-07'), $brand)), $brand);
        $this->assertSame(1, $day6['submitted']);
        $this->assertSame(1, $day7['submitted']);
    }

    // ── 30–31. SMM attribution and authorization ────────────────────────────

    public function test_smm_user_attribution_is_correct(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smmA = $this->smm();
        $brand = $this->readyBrand($manager);
        $this->log($smmA, $brand);

        $response = $this->actingAs($marketing)->getJson(route('smm-conversations.index', ['status' => 'pending']));
        $response->assertOk();
        $this->assertSame($smmA->name, $response->json('data.0.submitted_by'));
    }

    public function test_unauthorized_access_is_rejected(): void
    {
        $t = $this->workflowTeam();
        $this->readyBrand($t['manager']);

        foreach (['content', 'design', 'smm', 'manager'] as $role) {
            $this->actingAs($t[$role])->getJson(route('panels.marketing.conversations-summary'))->assertForbidden();
        }
    }

    // ── 32. Bounded query count ──────────────────────────────────────────────

    public function test_query_count_stays_bounded_as_brands_and_conversations_grow(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();

        for ($i = 0; $i < 3; $i++) {
            $brand = $this->readyBrand($manager);
            for ($j = 0; $j < 3; $j++) {
                $this->log($smm, $brand);
            }
        }

        // Warms permission/role caches so only the real query cost is measured.
        $this->actingAs($marketing)->getJson(route('panels.marketing.conversations-summary', $this->monthly('2026-10')))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($marketing)->getJson(route('panels.marketing.conversations-summary', $this->monthly('2026-10')))->assertOk();
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();

        $brand = $this->readyBrand($manager);
        for ($j = 0; $j < 5; $j++) {
            $this->log($smm, $brand);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($marketing)->getJson(route('panels.marketing.conversations-summary', $this->monthly('2026-10')))->assertOk();
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($before, $after, 'The brand-wise summary must not run per-brand or per-conversation queries.');
    }
}
