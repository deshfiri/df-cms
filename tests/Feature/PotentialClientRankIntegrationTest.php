<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PerformancePointEvent;
use App\Models\SmmClientConversation;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * End-to-end regression closing the two gaps found by the read-only
 * Performance-ranking audit:
 *
 *   SMM Client Conversation -> Marketing Potential Client approval
 *   -> Workflow Points -> final_score -> the LIVE Performance leaderboard's
 *   Rank (PerformanceController::index()), exercised entirely through the
 *   real HTTP conversation/decision endpoints — never a directly-inserted
 *   PerformancePointEvent row — so the whole chain is actually protected,
 *   not just finalScore() in isolation.
 *
 * Point values and the score formula are read from config/the real service,
 * never hardcoded into the assertions' expectations.
 */
class PotentialClientRankIntegrationTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkflowPermissions();
        Storage::fake('local');
        Permission::firstOrCreate(['name' => 'view performance', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function smm(string $name): User
    {
        $user = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content', 'view smm-panel']);
        $user->update(['name' => $name]);

        return $user;
    }

    /** Submits one conversation through the real HTTP endpoint — never a direct model create. */
    private function log(User $smm, Brand $brand): SmmClientConversation
    {
        $response = $this->actingAs($smm)->postJson(route('smm-conversations.store'), [
            'brand_id' => $brand->id,
            'reference' => 'Thread '.uniqid('', true),
            'idempotency_key' => 'key-'.uniqid('', true),
            'evidence' => UploadedFile::fake()->image('s.png', 200, 200),
        ]);
        $response->assertCreated();

        return SmmClientConversation::findOrFail($response->json('data.id'));
    }

    /** Approves as Potential Client through the real HTTP decision endpoint. */
    private function approve(User $marketing, SmmClientConversation $c): void
    {
        $this->actingAs($marketing)->postJson(route('smm-conversations.decide', $c), ['decision' => 'approved'])->assertOk();
    }

    /** @return array{response: TestResponse, rows: Collection} */
    private function leaderboard(User $viewer, string $period, ?string $department = null): array
    {
        $query = ['period' => $period];
        if ($department !== null) {
            $query['department'] = $department;
        }
        $response = $this->actingAs($viewer)->get(route('performance.index', $query));
        $response->assertOk();

        return ['response' => $response, 'rows' => $response->viewData('rows')];
    }

    private function rowFor(Collection $rows, User $user): ?array
    {
        return $rows->firstWhere('id', $user->id);
    }

    // ── 1–2. Department filter: SMM-only cohort, company-wide default ────────

    public function test_the_smm_department_filter_shows_only_smm_users(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view performance']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $content = $this->user('Content', ['manage raw-content']);
        $design = $this->user('Design', ['manage designer-content']);
        $smm = $this->smm('SMM Able');

        $board = $this->leaderboard($manager, '2026-10', 'Social Media Manager');
        $ids = $board['rows']->pluck('id')->all();

        $this->assertContains($smm->id, $ids);
        $this->assertNotContains($marketing->id, $ids);
        $this->assertNotContains($content->id, $ids);
        $this->assertNotContains($design->id, $ids);
    }

    public function test_the_company_wide_default_board_still_includes_smm_users(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view performance']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm('SMM Able');

        $board = $this->leaderboard($manager, '2026-10');
        $ids = $board['rows']->pluck('id')->all();

        $this->assertContains($smm->id, $ids);
        $this->assertContains($marketing->id, $ids, 'Adding the SMM department must not narrow the unfiltered, company-wide board.');
    }

    public function test_an_invalid_department_falls_back_to_the_company_wide_board(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view performance']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm('SMM Able');

        $board = $this->leaderboard($manager, '2026-10', 'Not A Real Department');

        $this->assertNull($board['response']->viewData('department'), 'An unrecognized department string is rejected, same as before this change.');
        $ids = $board['rows']->pluck('id')->all();
        $this->assertContains($smm->id, $ids);
        $this->assertContains($marketing->id, $ids);
    }

    // ── 3–6. Potential Client -> Workflow Points -> final_score -> Rank ──────

    public function test_more_approved_potential_clients_produces_a_better_live_leaderboard_rank(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view performance']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        $smmA = $this->smm('SMM Able');
        $smmB = $this->smm('SMM Baker');

        $this->atDhaka('2026-10-06 09:00');

        // A earns 4 approved Potential Clients first.
        for ($i = 0; $i < 4; $i++) {
            $this->approve($marketing, $this->log($smmA, $brand));
        }

        // First read of the live board. B has no activity yet.
        $before = $this->leaderboard($manager, '2026-10');
        $this->assertNotNull($this->rowFor($before['rows'], $smmA));
        $this->assertNull($this->rowFor($before['rows'], $smmB)['final_score']);

        // B now earns twice as many — all through the real review endpoint,
        // with no manual cache clearing anywhere in this test.
        for ($i = 0; $i < 8; $i++) {
            $this->approve($marketing, $this->log($smmB, $brand));
        }

        // Current configured values, read live — never hardcoded into this expectation.
        $perEvent = (int) config('performance.points.potential_client');
        $scorePerPoint = (float) config('performance.score_per_point');
        $this->assertSame(3, $perEvent, 'Sanity check against the documented current config value — report a diff, do not change it.');
        $this->assertSame(0.25, $scorePerPoint, 'Sanity check against the documented current config value — report a diff, do not change it.');
        $expectedAScore = round(4 * $perEvent * $scorePerPoint, 2);
        $expectedBScore = round(8 * $perEvent * $scorePerPoint, 2);

        // ── Exact attribution ──
        $this->assertSame(4, PerformancePointEvent::where('user_id', $smmA->id)->where('event_type', PerformancePointEvent::EVENT_POTENTIAL_CLIENT)->count());
        $this->assertSame(8, PerformancePointEvent::where('user_id', $smmB->id)->where('event_type', PerformancePointEvent::EVENT_POTENTIAL_CLIENT)->count());
        $this->assertSame(0, PerformancePointEvent::where('user_id', $marketing->id)->where('event_type', PerformancePointEvent::EVENT_POTENTIAL_CLIENT)->count(), 'The reviewer must never receive the beneficiary point.');
        $this->assertSame(12, PerformancePointEvent::where('event_type', PerformancePointEvent::EVENT_POTENTIAL_CLIENT)->count(), 'No other SMM user received any of these events.');

        // ── final_score via the real service, matching the configured formula ──
        $calc = app(PerformanceCalculationService::class);
        $scoreA = $calc->finalScore($smmA, '2026-10');
        $scoreB = $calc->finalScore($smmB, '2026-10');
        $this->assertEqualsWithDelta($expectedAScore, $scoreA['final_score'], 0.001);
        $this->assertEqualsWithDelta($expectedBScore, $scoreB['final_score'], 0.001);
        $this->assertGreaterThan($scoreA['final_score'], $scoreB['final_score']);

        // ── The live board, re-read with the SAME cache key — must reflect
        // B's new points automatically (PerformancePointEvent::saved() bumps
        // PerformanceBoardCache's version; nothing here calls Cache::forget
        // or PerformanceBoardCache::bump() directly). ──
        $after = $this->leaderboard($manager, '2026-10');
        $rowA = $this->rowFor($after['rows'], $smmA);
        $rowB = $this->rowFor($after['rows'], $smmB);

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertEqualsWithDelta($expectedAScore, $rowA['final_score'], 0.001);
        $this->assertEqualsWithDelta($expectedBScore, $rowB['final_score'], 0.001);
        $this->assertGreaterThan($rowA['final_score'], $rowB['final_score'], 'B\'s final_score must be higher.');
        $this->assertLessThan($rowA['rank'], $rowB['rank'], 'A smaller rank number is better — B must rank above A.');
    }

    // ── 7. SMM department cohort keeps the same ordering ─────────────────────

    public function test_the_smm_department_filter_preserves_the_same_ranking_within_its_own_cohort(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view performance']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $content = $this->user('Content', ['manage raw-content']);
        $design = $this->user('Design', ['manage designer-content']);
        $brand = $this->readyBrand($manager);
        $smmA = $this->smm('SMM Able');
        $smmB = $this->smm('SMM Baker');

        $this->atDhaka('2026-10-06 09:00');
        for ($i = 0; $i < 4; $i++) {
            $this->approve($marketing, $this->log($smmA, $brand));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->approve($marketing, $this->log($smmB, $brand));
        }

        $board = $this->leaderboard($manager, '2026-10', 'Social Media Manager');
        $ids = $board['rows']->pluck('id')->all();

        $this->assertContains($smmA->id, $ids);
        $this->assertContains($smmB->id, $ids);
        $this->assertNotContains($marketing->id, $ids);
        $this->assertNotContains($content->id, $ids);
        $this->assertNotContains($design->id, $ids);

        $rowA = $this->rowFor($board['rows'], $smmA);
        $rowB = $this->rowFor($board['rows'], $smmB);
        $this->assertLessThan($rowA['rank'], $rowB['rank'], 'B must still rank above A inside the SMM-only cohort — rank is relative to the selected cohort.');
    }

    // ── 14. Cross-month: the approval month receives the point, never the submission month ──

    public function test_a_late_september_submission_approved_in_october_credits_octobers_rank_not_septembers(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view performance']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);
        $smm = $this->smm('SMM Able');

        $this->atDhaka('2026-09-30 22:00');
        $conversation = $this->log($smm, $brand);

        $this->atDhaka('2026-10-02 09:00');
        $this->approve($marketing, $conversation);

        $expected = round((int) config('performance.points.potential_client') * (float) config('performance.score_per_point'), 2);

        $september = app(PerformanceCalculationService::class)->finalScore($smm, '2026-09');
        $october = app(PerformanceCalculationService::class)->finalScore($smm, '2026-10');
        $this->assertNull($september['final_score'], 'September must not receive a point awarded in October.');
        $this->assertEqualsWithDelta($expected, $october['final_score'], 0.001);

        // And the live leaderboard for each month agrees.
        $septBoard = $this->leaderboard($manager, '2026-09');
        $octBoard = $this->leaderboard($manager, '2026-10');
        $this->assertNull($this->rowFor($septBoard['rows'], $smm)['final_score']);
        $this->assertEqualsWithDelta($expected, $this->rowFor($octBoard['rows'], $smm)['final_score'], 0.001);
    }
}
