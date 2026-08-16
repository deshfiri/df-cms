<?php

namespace Tests\Feature;

use App\Events\CallRinging;
use App\Events\CallSignal;
use App\Events\CallStatusChanged;
use App\Models\Call;
use App\Models\User;
use App\Services\CallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AudioCallTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function ringing(User $caller, User $callee): Call
    {
        return app(CallService::class)->start($caller, $callee)['call'];
    }

    // ── Starting ─────────────────────────────────────────────────────────

    public function test_a_user_can_start_a_call_and_only_the_callee_is_rung(): void
    {
        Event::fake([CallRinging::class]);

        $caller = $this->user();
        $callee = $this->user();

        $this->actingAs($caller)
            ->postJson(route('calls.start', $callee))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('call.status', Call::STATUS_RINGING);

        $this->assertDatabaseHas('calls', [
            'caller_id' => $caller->id,
            'callee_id' => $callee->id,
            'status'    => Call::STATUS_RINGING,
        ]);

        // Signalling must never reach a shared channel.
        Event::assertDispatched(CallRinging::class, function (CallRinging $event) use ($callee) {
            $channels = collect($event->broadcastOn())->map(fn ($c) => (string) $c);

            return $channels->count() === 1
                && $channels->first() === 'private-App.Models.User.' . $callee->id;
        });
    }

    public function test_a_guest_cannot_start_a_call(): void
    {
        $this->postJson(route('calls.start', $this->user()))->assertUnauthorized();
    }

    public function test_a_user_cannot_call_themselves(): void
    {
        $me = $this->user();

        $this->actingAs($me)
            ->postJson(route('calls.start', $me))
            ->assertStatus(422);

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_an_inactive_user_cannot_be_called(): void
    {
        $callee = $this->user();
        $callee->update(['is_active' => false]);

        $this->actingAs($this->user())
            ->postJson(route('calls.start', $callee))
            ->assertStatus(422);
    }

    // ── Busy / concurrency ───────────────────────────────────────────────

    public function test_calling_a_busy_user_is_refused_and_recorded(): void
    {
        $callee = $this->user();
        $this->ringing($this->user(), $callee);   // callee already ringing

        $third = $this->user();

        $this->actingAs($third)
            ->postJson(route('calls.start', $callee))
            ->assertStatus(409);

        $this->assertDatabaseHas('calls', [
            'caller_id'      => $third->id,
            'callee_id'      => $callee->id,
            'status'         => Call::STATUS_BUSY,
            'failure_reason' => 'callee_busy',
        ]);
    }

    public function test_a_caller_already_on_a_call_cannot_start_another(): void
    {
        $caller = $this->user();
        $this->ringing($caller, $this->user());

        $this->actingAs($caller)
            ->postJson(route('calls.start', $this->user()))
            ->assertStatus(409);
    }

    public function test_simultaneous_calls_between_the_same_pair_collapse_into_one(): void
    {
        $a = $this->user();
        $b = $this->user();

        $first = $this->ringing($a, $b);

        // b now rings a. Rather than a second call or a busy error, b is told
        // to answer the one already ringing.
        $response = $this->actingAs($b)
            ->postJson(route('calls.start', $a))
            ->assertOk()
            ->assertJsonPath('glare', true);

        $this->assertSame($first->uuid, $response->json('call.uuid'));
        $this->assertDatabaseCount('calls', 1);
    }

    // ── Accept / reject / end ────────────────────────────────────────────

    public function test_the_callee_can_accept_and_the_caller_is_told(): void
    {
        Event::fake([CallStatusChanged::class]);

        $caller = $this->user();
        $callee = $this->user();
        $call   = $this->ringing($caller, $callee);

        $this->actingAs($callee)
            ->postJson(route('calls.accept', $call->uuid))
            ->assertOk()
            ->assertJsonPath('call.status', Call::STATUS_ACCEPTED);

        $this->assertNotNull($call->fresh()->answered_at);

        Event::assertDispatched(CallStatusChanged::class,
            fn (CallStatusChanged $e) => $e->recipientId === $caller->id);
    }

    public function test_the_caller_cannot_accept_their_own_call(): void
    {
        $caller = $this->user();
        $call   = $this->ringing($caller, $this->user());

        $this->actingAs($caller)
            ->postJson(route('calls.accept', $call->uuid))
            ->assertStatus(403);
    }

    public function test_accepting_twice_is_safe(): void
    {
        $callee = $this->user();
        $call   = $this->ringing($this->user(), $callee);

        $this->actingAs($callee)->postJson(route('calls.accept', $call->uuid))->assertOk();
        $answeredAt = $call->fresh()->answered_at;

        $this->actingAs($callee)->postJson(route('calls.accept', $call->uuid))->assertOk();

        // Still one acceptance — the timestamp did not move.
        $this->assertEquals($answeredAt, $call->fresh()->answered_at);
    }

    public function test_the_callee_can_reject(): void
    {
        $callee = $this->user();
        $call   = $this->ringing($this->user(), $callee);

        $this->actingAs($callee)->postJson(route('calls.reject', $call->uuid))->assertOk();

        $this->assertSame(Call::STATUS_REJECTED, $call->fresh()->status);
    }

    public function test_rejecting_an_already_finished_call_is_safe(): void
    {
        $callee = $this->user();
        $call   = $this->ringing($this->user(), $callee);

        $this->actingAs($callee)->postJson(route('calls.reject', $call->uuid))->assertOk();
        $this->actingAs($callee)->postJson(route('calls.reject', $call->uuid))->assertOk();

        $this->assertSame(Call::STATUS_REJECTED, $call->fresh()->status);
    }

    public function test_a_caller_hanging_up_before_pickup_cancels_rather_than_ends(): void
    {
        $caller = $this->user();
        $call   = $this->ringing($caller, $this->user());

        $this->actingAs($caller)->postJson(route('calls.end', $call->uuid))->assertOk();

        $this->assertSame(Call::STATUS_CANCELLED, $call->fresh()->status);
        $this->assertNull($call->fresh()->duration_seconds);
    }

    public function test_ending_an_answered_call_records_a_duration(): void
    {
        $caller = $this->user();
        $callee = $this->user();
        $call   = $this->ringing($caller, $callee);

        app(CallService::class)->accept($call, $callee);

        // Wind the answer back so the elapsed time is deterministic.
        $call->fresh()->forceFill(['answered_at' => now()->subSeconds(75)])->save();

        $this->actingAs($caller)->postJson(route('calls.end', $call->uuid))->assertOk();

        $ended = $call->fresh();
        $this->assertSame(Call::STATUS_ENDED, $ended->status);
        $this->assertSame(75, $ended->duration_seconds);
        $this->assertSame($caller->id, $ended->ended_by);
        $this->assertSame('01:15', $ended->formattedDuration());
    }

    public function test_a_stranger_cannot_end_someone_elses_call(): void
    {
        $call = $this->ringing($this->user(), $this->user());

        $this->actingAs($this->user())
            ->postJson(route('calls.end', $call->uuid))
            ->assertStatus(403);

        $this->assertSame(Call::STATUS_RINGING, $call->fresh()->status);
    }

    // ── Signalling ───────────────────────────────────────────────────────

    public function test_signalling_is_relayed_only_to_the_other_participant(): void
    {
        Event::fake([CallSignal::class]);

        $caller = $this->user();
        $callee = $this->user();
        $call   = $this->ringing($caller, $callee);
        app(CallService::class)->accept($call, $callee);

        $this->actingAs($caller)
            ->postJson(route('calls.signal', $call->uuid), [
                'type'    => 'offer',
                'payload' => ['type' => 'offer', 'sdp' => 'v=0...'],
            ])->assertOk();

        Event::assertDispatched(CallSignal::class, function (CallSignal $e) use ($callee) {
            $channels = collect($e->broadcastOn())->map(fn ($c) => (string) $c);

            return $e->recipientId === $callee->id
                && $e->type === 'offer'
                && $channels->first() === 'private-App.Models.User.' . $callee->id;
        });
    }

    public function test_a_non_participant_cannot_inject_signalling(): void
    {
        Event::fake([CallSignal::class]);

        $call = $this->ringing($this->user(), $this->user());

        $this->actingAs($this->user())
            ->postJson(route('calls.signal', $call->uuid), [
                'type'    => 'ice',
                'payload' => ['candidate' => 'spoofed'],
            ])->assertStatus(403);

        Event::assertNotDispatched(CallSignal::class);
    }

    public function test_signalling_is_rejected_once_the_call_has_finished(): void
    {
        Event::fake([CallSignal::class]);

        $caller = $this->user();
        $callee = $this->user();
        $call   = $this->ringing($caller, $callee);

        app(CallService::class)->reject($call, $callee);

        $this->actingAs($caller)
            ->postJson(route('calls.signal', $call->uuid), [
                'type'    => 'ice',
                'payload' => ['candidate' => 'late'],
            ])->assertStatus(409);

        Event::assertNotDispatched(CallSignal::class);
    }

    public function test_an_unknown_signal_type_is_rejected(): void
    {
        $caller = $this->user();
        $call   = $this->ringing($caller, $this->user());

        $this->actingAs($caller)
            ->postJson(route('calls.signal', $call->uuid), [
                'type'    => 'shell',
                'payload' => [],
            ])->assertStatus(422);
    }

    // ── Timeout / reconciliation ─────────────────────────────────────────

    public function test_an_unanswered_call_becomes_missed_and_both_sides_are_told(): void
    {
        Event::fake([CallStatusChanged::class]);

        $call = $this->ringing($this->user(), $this->user());
        $call->forceFill(['started_at' => now()->subSeconds(120)])->save();

        app(CallService::class)->reconcileStaleCalls();

        $missed = $call->fresh();
        $this->assertSame(Call::STATUS_MISSED, $missed->status);
        $this->assertSame('ring_timeout', $missed->failure_reason);

        // Nobody acted, so both participants need telling.
        Event::assertDispatchedTimes(CallStatusChanged::class, 2);
    }

    public function test_a_call_still_within_the_ring_window_is_left_alone(): void
    {
        $call = $this->ringing($this->user(), $this->user());

        app(CallService::class)->reconcileStaleCalls();

        $this->assertSame(Call::STATUS_RINGING, $call->fresh()->status);
    }

    public function test_an_abandoned_answered_call_is_force_ended(): void
    {
        $callee = $this->user();
        $call   = $this->ringing($this->user(), $callee);
        app(CallService::class)->accept($call, $callee);

        $call->fresh()->forceFill(['answered_at' => now()->subDays(1)])->save();

        app(CallService::class)->reconcileStaleCalls();

        $this->assertSame(Call::STATUS_ENDED, $call->fresh()->status);
        $this->assertSame('max_duration', $call->fresh()->failure_reason);
    }

    public function test_a_settled_call_frees_both_users_to_call_again(): void
    {
        $caller = $this->user();
        $callee = $this->user();
        $call   = $this->ringing($caller, $callee);

        app(CallService::class)->end($call, $caller);

        $this->actingAs($caller)
            ->postJson(route('calls.start', $callee))
            ->assertOk();
    }

    // ── ICE configuration ────────────────────────────────────────────────

    public function test_ice_endpoint_requires_authentication(): void
    {
        $this->getJson(route('calls.ice'))->assertUnauthorized();
    }

    public function test_turn_credentials_are_time_limited_and_never_expose_the_secret(): void
    {
        config([
            'webrtc.turn_urls'   => ['turn:turn.dms.deshfiri.com:3478'],
            'webrtc.turn_secret' => 'super-secret-value',
            'webrtc.turn_ttl'    => 600,
        ]);

        $user = $this->user();

        $response = $this->actingAs($user)->getJson(route('calls.ice'))->assertOk();

        $turn = collect($response->json('iceServers'))
            ->first(fn ($s) => isset($s['credential']));

        $this->assertNotNull($turn);
        // username is "<expiry>:<user id>" and the credential is derived, so
        // the shared secret itself never leaves the server.
        [$expiry, $identifier] = explode(':', $turn['username']);
        $this->assertGreaterThan(time(), (int) $expiry);
        $this->assertSame((string) $user->id, $identifier);

        $expected = base64_encode(hash_hmac('sha1', $turn['username'], 'super-secret-value', true));
        $this->assertSame($expected, $turn['credential']);

        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
    }

    public function test_relay_only_mode_is_off_by_default(): void
    {
        $this->actingAs($this->user())
            ->getJson(route('calls.ice'))
            ->assertOk()
            ->assertJsonPath('iceTransportPolicy', 'all');
    }

    // ── History ──────────────────────────────────────────────────────────

    public function test_history_shows_only_the_users_own_calls(): void
    {
        $me = $this->user();
        $mine = $this->ringing($me, $this->user());
        $theirs = $this->ringing($this->user(), $this->user());

        $uuids = collect($this->actingAs($me)->getJson(route('calls.history'))->json('calls'))
            ->pluck('uuid');

        $this->assertTrue($uuids->contains($mine->uuid));
        $this->assertFalse($uuids->contains($theirs->uuid));
    }
}
