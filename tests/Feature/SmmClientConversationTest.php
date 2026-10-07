<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PerformancePointEvent;
use App\Models\Product;
use App\Models\SmmClientConversation;
use App\Models\User;
use App\Notifications\ConversationReadyForReview;
use App\Notifications\ConversationReviewed;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * SMM client conversations: logged with screenshot evidence, verified by
 * Marketing, and rewarded with a Potential Client point to the exact submitter.
 */
class SmmClientConversationTest extends TestCase
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

    private function product(Brand $brand): Product
    {
        return Product::create(['brand_id' => $brand->id, 'name' => 'Product '.uniqid(), 'created_by' => 1]);
    }

    /** @return array<string, mixed> */
    private function payload(Brand $brand, ?Product $product = null, array $extra = []): array
    {
        return array_merge([
            'brand_id' => $brand->id,
            'product_id' => $product?->id,
            'reference' => 'Thread '.uniqid(),
            'note' => 'Asked about pricing',
            'idempotency_key' => 'key-'.uniqid(),
            'evidence' => UploadedFile::fake()->image('screenshot.png', 320, 240),
        ], $extra);
    }

    private function submit(User $smm, array $payload): TestResponse
    {
        return $this->actingAs($smm)->post(route('smm-conversations.store'), $payload, ['Accept' => 'application/json']);
    }

    private function decide(User $reviewer, SmmClientConversation $c, string $decision): TestResponse
    {
        return $this->actingAs($reviewer)->postJson(route('smm-conversations.decide', $c), ['decision' => $decision]);
    }

    private function conversationFor(User $smm, Brand $brand, ?Product $product = null): SmmClientConversation
    {
        $response = $this->submit($smm, $this->payload($brand, $product));
        $response->assertCreated();

        return SmmClientConversation::findOrFail($response->json('data.id'));
    }

    /** Points one approved conversation earns, read from config so the tests track the configured value. */
    private function pointValue(): int
    {
        return (int) config('performance.points.potential_client');
    }

    private function pointsFor(User $user): int
    {
        return (int) PerformancePointEvent::where('user_id', $user->id)
            ->where('event_type', PerformancePointEvent::EVENT_POTENTIAL_CLIENT)
            ->sum('points');
    }

    // ── 1–4. Submission, validation and secure storage ────────────────────────

    public function test_an_smm_user_can_submit_brand_and_product_conversation_evidence(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $product = $this->product($brand);
        $smm = $this->smm();

        $this->submit($smm, $this->payload($brand, $product))
            ->assertCreated()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.product', $product->name);

        $this->assertDatabaseHas('smm_client_conversations', ['brand_id' => $brand->id, 'product_id' => $product->id, 'submitted_by' => $smm->id]);
    }

    public function test_a_product_from_another_brand_is_rejected_server_side(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brandA = $this->readyBrand($manager);
        $brandB = $this->readyBrand($manager);
        $productOfB = $this->product($brandB);

        $this->submit($this->smm(), $this->payload($brandA, $productOfB))
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
        $this->assertSame(0, SmmClientConversation::count());
    }

    public function test_the_screenshot_is_stored_under_the_brand_and_never_exposed_by_path(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $c = $this->conversationFor($this->smm(), $brand);

        $this->assertStringStartsWith('smm-conversations/'.$brand->id.'/', $c->evidence_path);
        Storage::disk($c->evidence_disk)->assertExists($c->evidence_path);

        $body = json_encode($this->actingAs($this->smm())->getJson(route('smm-conversations.index'))->json());
        $this->assertStringNotContainsString($c->evidence_path, $body, 'The storage path must never reach the browser.');
    }

    public function test_only_safe_image_formats_are_accepted_and_real_bytes_are_checked(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $smm = $this->smm();

        $this->submit($smm, $this->payload($brand, null, [
            'evidence' => UploadedFile::fake()->create('evil.svg', 4, 'image/svg+xml'),
        ]))->assertStatus(422)->assertJsonValidationErrors('evidence');

        $this->submit($smm, $this->payload($brand, null, [
            'evidence' => UploadedFile::fake()->create('fake.png', 4, 'image/png'),
        ]))->assertStatus(422)->assertJsonValidationErrors('evidence');
    }

    // ── 5–9. Marketing verification and the exact Performance effect ──────────

    public function test_marketing_sees_the_pending_review_and_approves_a_potential_client(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $c = $this->conversationFor($smm, $brand);

        $this->actingAs($marketing)->getJson(route('smm-conversations.index', ['status' => 'pending']))
            ->assertOk()->assertJsonFragment(['id' => $c->id]);

        $this->decide($marketing, $c, 'approved')->assertOk()->assertJsonPath('data.review_status', 'approved');
        $this->assertSame($this->pointValue(), $this->pointsFor($smm));
    }

    public function test_the_correct_smm_user_gets_the_point_and_their_performance_rises(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $calc = app(PerformanceCalculationService::class);
        $before = $calc->finalScore($smm, '2026-10')['final_score'];

        $this->decide($marketing, $this->conversationFor($smm, $brand), 'approved')->assertOk();

        $expected = (float) config('performance.score_per_point') * (int) config('performance.points.potential_client');
        $this->assertEqualsWithDelta(($before ?? 0) + $expected, $calc->finalScore($smm, '2026-10')['final_score'], 0.001);
    }

    public function test_a_rejection_gives_no_point_and_no_performance_increase(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $calc = app(PerformanceCalculationService::class);

        $this->decide($marketing, $this->conversationFor($smm, $brand), 'rejected')->assertOk()->assertJsonPath('data.review_status', 'rejected');

        $this->assertSame(0, $this->pointsFor($smm));
        $this->assertNull($calc->finalScore($smm, '2026-10')['final_score']);
    }

    public function test_ten_submissions_with_six_approvals_award_exactly_six_events(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();

        $records = [];
        for ($i = 0; $i < 10; $i++) {
            $records[] = $this->conversationFor($smm, $brand);
        }
        foreach (array_slice($records, 0, 6) as $c) {
            $this->decide($marketing, $c, 'approved')->assertOk();
        }
        foreach (array_slice($records, 6) as $c) {
            $this->decide($marketing, $c, 'rejected')->assertOk();
        }

        $this->assertSame(6, PerformancePointEvent::where('event_type', PerformancePointEvent::EVENT_POTENTIAL_CLIENT)->count());
        $this->assertSame(6 * (int) config('performance.points.potential_client'), $this->pointsFor($smm));
    }

    // ── 10–11. Attribution per user ───────────────────────────────────────────

    public function test_smm_a_and_smm_b_attribution_stays_separate(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smmA = $this->smm();
        $smmB = $this->smm();

        $this->decide($marketing, $this->conversationFor($smmA, $brand), 'approved')->assertOk();
        $this->decide($marketing, $this->conversationFor($smmB, $brand), 'rejected')->assertOk();

        $this->assertSame((int) config('performance.points.potential_client'), $this->pointsFor($smmA));
        $this->assertSame(0, $this->pointsFor($smmB));
        $this->actingAs($smmB)->getJson(route('smm-conversations.index'))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // ── 12. Idempotent decisions ──────────────────────────────────────────────

    public function test_a_duplicate_approval_does_not_duplicate_the_point(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $c = $this->conversationFor($smm, $brand);

        $this->decide($marketing, $c, 'approved')->assertOk();
        $this->decide($marketing, $c, 'approved')->assertOk();

        $this->assertSame(1, PerformancePointEvent::where('source_id', $c->id)->where('event_type', PerformancePointEvent::EVENT_POTENTIAL_CLIENT)->count());
    }

    public function test_a_decision_cannot_be_flipped_once_made(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $c = $this->conversationFor($smm, $brand);

        $this->decide($marketing, $c, 'approved')->assertOk();
        $this->decide($marketing, $c, 'rejected')->assertStatus(422);

        $this->assertSame('approved', $c->fresh()->review_status);
        $this->assertSame($this->pointValue(), $this->pointsFor($smm));
    }

    public function test_a_retried_submission_with_the_same_key_returns_the_same_record(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $smm = $this->smm();
        $payload = $this->payload($brand, null, ['idempotency_key' => 'retry-key-123']);

        $first = $this->submit($smm, $payload)->assertCreated();
        $second = $this->submit($smm, $this->payload($brand, null, ['idempotency_key' => 'retry-key-123']));

        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, SmmClientConversation::count());
    }

    public function test_a_likely_duplicate_is_warned_and_then_allowed_on_confirmation(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $smm = $this->smm();
        $this->submit($smm, $this->payload($brand, null, ['reference' => 'Same thread']))->assertCreated();

        $this->submit($smm, $this->payload($brand, null, ['reference' => 'Same thread']))
            ->assertStatus(422)->assertJsonValidationErrors('duplicate');

        $this->submit($smm, $this->payload($brand, null, ['reference' => 'Same thread', 'confirm_duplicate' => true]))
            ->assertCreated();
        $this->assertSame(2, SmmClientConversation::count());
    }

    // ── 13–15. Authorization and ownership ────────────────────────────────────

    public function test_an_unauthorized_reviewer_is_blocked(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $content = $this->user('Content', ['manage raw-content']);
        $smmB = $this->smm();
        $c = $this->conversationFor($this->smm(), $brand);

        $this->decide($content, $c, 'approved')->assertForbidden();
        $this->decide($smmB, $c, 'approved')->assertForbidden();
        $this->assertSame('pending', $c->fresh()->review_status);
    }

    public function test_an_smm_user_cannot_approve_their_own_record(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $selfReviewer = $this->user('Social Media Manager', ['manage smm-collection', 'manage publishing-review']);
        $c = $this->conversationFor($selfReviewer, $brand);

        $this->decide($selfReviewer, $c, 'approved')->assertStatus(422);
        $this->assertSame(0, $this->pointsFor($selfReviewer));
    }

    public function test_smm_a_cannot_read_or_change_smm_b_evidence(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smmA = $this->smm();
        $smmB = $this->smm();
        $c = $this->conversationFor($smmB, $brand);

        $this->actingAs($smmA)->get(route('smm-conversations.evidence', $c))->assertForbidden();
        $this->decide($smmA, $c, 'approved')->assertForbidden();
        $this->actingAs($smmA)->getJson(route('smm-conversations.index'))->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('pending', $c->fresh()->review_status);
    }

    public function test_a_cross_brand_product_tamper_is_rejected(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brandA = $this->readyBrand($manager);
        $brandB = $this->readyBrand($manager);
        $productOfB = $this->product($brandB);

        $this->submit($this->smm(), $this->payload($brandA, $productOfB))->assertStatus(422);
    }

    public function test_evidence_is_viewable_by_the_submitter_and_marketing_but_not_content(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $content = $this->user('Content', ['manage raw-content']);
        $c = $this->conversationFor($smm, $brand);

        $this->actingAs($smm)->get(route('smm-conversations.evidence', $c))->assertOk();
        $this->actingAs($marketing)->get(route('smm-conversations.evidence', $c))->assertOk();
        $this->actingAs($content)->get(route('smm-conversations.evidence', $c))->assertForbidden();
    }

    public function test_a_guest_cannot_open_evidence(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $c = $this->conversationFor($this->smm(), $brand);

        // The records above were made by a signed-in SMM user. Drop that session first,
        // so this request really is a guest.
        $this->app['auth']->forgetGuards();

        $this->get(route('smm-conversations.evidence', $c))->assertRedirect(route('login'));
    }

    public function test_evidence_is_served_no_store_so_a_post_logout_replay_is_blocked(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $smm = $this->smm();
        $c = $this->conversationFor($smm, $brand);

        $response = $this->actingAs($smm)->get(route('smm-conversations.evidence', $c));

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ── 16–19. Notifications ──────────────────────────────────────────────────

    public function test_marketing_is_notified_on_submission_and_only_the_submitter_on_a_decision(): void
    {
        Notification::fake();
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smmA = $this->smm();
        $smmB = $this->smm();

        $c = $this->conversationFor($smmA, $brand);
        Notification::assertSentTo($marketing, ConversationReadyForReview::class);
        Notification::assertNotSentTo($smmB, ConversationReadyForReview::class);

        $this->decide($marketing, $c, 'approved')->assertOk();
        Notification::assertSentToTimes($smmA, ConversationReviewed::class, 1);
        Notification::assertNotSentTo($smmB, ConversationReviewed::class);
    }

    // ── 20–23. Period reporting and current pending work ──────────────────────

    public function test_smm_reporting_counts_follow_the_selected_period_and_pending_is_always_current(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();

        $this->atDhaka('2026-09-15 10:00');
        $approvedSept = $this->conversationFor($smm, $brand);

        $this->atDhaka('2026-10-06 10:00');
        $pendingOct = $this->conversationFor($smm, $brand);
        $this->decide($marketing, $this->conversationFor($smm, $brand), 'approved')->assertOk();

        $this->atDhaka('2026-10-07 10:00');
        $this->decide($marketing, $approvedSept, 'approved')->assertOk();

        $october = $this->actingAs($smm)->get(route('panels.smm', ['period' => 'monthly', 'month' => '2026-10']))->viewData('conversations');
        // October: two records submitted, and two approvals reviewed in October
        // (one on the 6th, and September's record reviewed on the 7th). Three points each.
        $this->assertSame(2, $october['submitted']);
        $this->assertSame(2, $october['approved']);
        $this->assertSame(1, $october['pending_review'], 'Pending work is current, so it is not limited to the selected month.');
        $this->assertSame(6, $october['potential_client_points']);

        $september = $this->actingAs($smm)->get(route('panels.smm', ['period' => 'monthly', 'month' => '2026-09']))->viewData('conversations');
        $this->assertSame(1, $september['submitted']);
        $this->assertSame(0, $september['approved'], 'The September approval was reviewed on the 7th, so it counts in October.');
        $this->assertSame(1, $september['pending_review'], 'Current pending work is still visible from a historical filter.');
        $this->assertNotNull($pendingOct->fresh());
    }

    /**
     * The row list itself (not just the activity metrics tested above) now
     * follows the selected period, by submitted_at — for both the SMM
     * submitter's own view and Marketing's review queue.
     */
    public function test_the_conversation_row_list_follows_the_selected_period_for_smm_and_marketing(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();

        $this->atDhaka('2026-09-15 10:00');
        $septConversation = $this->conversationFor($smm, $brand);

        $this->atDhaka('2026-10-06 10:00');
        $octConversation = $this->conversationFor($smm, $brand);

        $smmSept = $this->actingAs($smm)->getJson(route('smm-conversations.index', ['period' => 'monthly', 'month' => '2026-09']))->json('data');
        $smmOct = $this->actingAs($smm)->getJson(route('smm-conversations.index', ['period' => 'monthly', 'month' => '2026-10']))->json('data');
        $this->assertSame([$septConversation->id], collect($smmSept)->pluck('id')->all());
        $this->assertSame([$octConversation->id], collect($smmOct)->pluck('id')->all());

        $mktSept = $this->actingAs($marketing)->getJson(route('smm-conversations.index', ['status' => 'pending', 'period' => 'monthly', 'month' => '2026-09']))->json('data');
        $mktOct = $this->actingAs($marketing)->getJson(route('smm-conversations.index', ['status' => 'pending', 'period' => 'monthly', 'month' => '2026-10']))->json('data');
        $this->assertSame([$septConversation->id], collect($mktSept)->pluck('id')->all());
        $this->assertSame([$octConversation->id], collect($mktOct)->pluck('id')->all());
    }

    /** Section 11/13: Client Conversations respect Brand, for both SMM and Marketing. */
    public function test_client_conversations_respect_brand(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brandA = $this->readyBrand($manager);
        $brandB = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $convA = $this->conversationFor($smm, $brandA);
        $convB = $this->conversationFor($smm, $brandB);

        $smmBrandA = $this->actingAs($smm)->getJson(route('smm-conversations.index', ['brand_id' => $brandA->id]))->json('data');
        $this->assertSame([$convA->id], collect($smmBrandA)->pluck('id')->all());

        $mktBrandB = $this->actingAs($marketing)->getJson(route('smm-conversations.index', ['status' => 'pending', 'brand_id' => $brandB->id]))->json('data');
        $this->assertSame([$convB->id], collect($mktBrandB)->pluck('id')->all());

        $all = $this->actingAs($marketing)->getJson(route('smm-conversations.index', ['status' => 'pending']))->json('data');
        $this->assertCount(2, $all);
    }

    /** An unauthorized/invalid brand_id (never created, so not in anyone's eligible set) is ignored, not trusted. */
    public function test_an_invalid_brand_id_on_conversations_falls_back_to_all_brands(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $smm = $this->smm();
        $c = $this->conversationFor($smm, $brand);

        $response = $this->actingAs($smm)->getJson(route('smm-conversations.index', ['brand_id' => 999999]))->json('data');
        $this->assertSame([$c->id], collect($response)->pluck('id')->all());
    }

    public function test_conversation_review_stays_available_while_the_brand_checklist_is_on_hold(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $smm = $this->smm();
        $c = $this->conversationFor($smm, $brand);
        $brand->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Hold']);

        $this->decide($marketing, $c, 'approved')->assertOk();
        $this->assertSame($this->pointValue(), $this->pointsFor($smm));
    }
}
