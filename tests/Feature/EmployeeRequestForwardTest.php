<?php

namespace Tests\Feature;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Notifications\RequestForwarded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Forwarding: instead of answering a request sent to them, a recipient may
 * hand their own copy on to someone else. The whole hand-off chain —
 * "Ahsan -> Moulin -> Salman" — is documented (EmployeeRequest::chainFor())
 * rather than just showing whoever holds it now.
 */
class EmployeeRequestForwardTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return tap(User::factory()->create(['is_active' => true]))->update(['name' => $name]);
    }

    private function file(User $from, array $to): EmployeeRequest
    {
        $this->actingAs($from)->postJson(route('requests.store'), [
            'subject' => 'Need approval', 'message' => 'Please review.',
            'recipient_ids' => collect($to)->pluck('id')->all(),
        ])->assertOk();

        return EmployeeRequest::latest('id')->firstOrFail();
    }

    public function test_a_recipient_can_forward_their_copy_to_someone_else(): void
    {
        Notification::fake();
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $moulin = $this->user('Moulin');

        $request = $this->file($requester, [$ahsan]);

        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), [
            'to_user_id' => $moulin->id, 'note' => 'You handle this better.',
        ])->assertOk()->assertJson(['success' => true]);

        $request->refresh()->load('recipients');
        $this->assertSame([$moulin->id], $request->recipients->pluck('id')->all());
        $this->assertSame(EmployeeRequest::STATUS_PENDING, $request->recipients->first()->pivot->status);

        $this->assertDatabaseHas('employee_request_forwards', [
            'employee_request_id' => $request->id, 'from_user_id' => $ahsan->id, 'to_user_id' => $moulin->id,
            'note' => 'You handle this better.',
        ]);

        Notification::assertSentTo($moulin, RequestForwarded::class);
    }

    public function test_the_full_chain_is_documented_across_multiple_hops(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $moulin = $this->user('Moulin');
        $salman = $this->user('Salman');

        $request = $this->file($requester, [$ahsan]);
        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $moulin->id])->assertOk();
        $this->actingAs($moulin)->postJson(route('requests.forward', $request), ['to_user_id' => $salman->id])->assertOk();

        $request->refresh()->load('forwards');
        $chain = $request->chainFor($salman->id);

        $this->assertSame([$ahsan->id, $moulin->id, $salman->id], $chain);
    }

    public function test_the_chain_is_shown_to_the_requester_in_the_list(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $moulin = $this->user('Moulin');

        $request = $this->file($requester, [$ahsan]);
        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $moulin->id])->assertOk();

        $row = collect(
            $this->actingAs($requester)->getJson(route('requests.index'), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->json('data')
        )->firstOrFail();

        $this->assertStringContainsString('Ahsan', $row['recipients']);
        $this->assertStringContainsString('Moulin', $row['recipients']);
    }

    public function test_the_new_recipient_can_respond_normally_after_being_forwarded_to(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $moulin = $this->user('Moulin');

        $request = $this->file($requester, [$ahsan]);
        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $moulin->id])->assertOk();

        $this->actingAs($moulin)->postJson(route('requests.respond', $request), ['status' => 'Approved'])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(EmployeeRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_the_original_recipient_can_no_longer_act_on_it_once_forwarded(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $moulin = $this->user('Moulin');

        $request = $this->file($requester, [$ahsan]);
        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $moulin->id])->assertOk();

        $this->actingAs($ahsan)->postJson(route('requests.respond', $request), ['status' => 'Approved'])
            ->assertForbidden();
        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $requester->id])
            ->assertForbidden();
    }

    public function test_a_stranger_cannot_forward_a_request_not_sent_to_them(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $stranger = $this->user('Stranger');

        $request = $this->file($requester, [$ahsan]);

        $this->actingAs($stranger)->postJson(route('requests.forward', $request), ['to_user_id' => $ahsan->id])
            ->assertForbidden();
    }

    public function test_you_cannot_forward_to_yourself(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');

        $request = $this->file($requester, [$ahsan]);

        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $ahsan->id])
            ->assertStatus(422);
    }

    public function test_you_cannot_forward_back_to_whoever_filed_the_request(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');

        $request = $this->file($requester, [$ahsan]);

        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $requester->id])
            ->assertStatus(422);
    }

    public function test_you_cannot_forward_to_someone_who_is_already_a_recipient(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $moulin = $this->user('Moulin');

        $request = $this->file($requester, [$ahsan, $moulin]);

        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $moulin->id])
            ->assertStatus(422);
    }

    public function test_a_settled_request_can_no_longer_be_forwarded(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $moulin = $this->user('Moulin');

        $request = $this->file($requester, [$ahsan]);
        $this->actingAs($ahsan)->postJson(route('requests.respond', $request), ['status' => 'Approved'])->assertOk();

        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $moulin->id])
            ->assertStatus(422);
    }

    public function test_forwarding_writes_an_activity_log_entry(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $moulin = $this->user('Moulin');

        $request = $this->file($requester, [$ahsan]);
        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $moulin->id])->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'Request', 'action' => 'Forwarded', 'user_id' => $ahsan->id,
        ]);
    }

    public function test_a_recipient_with_multiple_slots_only_forwards_their_own(): void
    {
        $requester = $this->user('Requester');
        $ahsan = $this->user('Ahsan');
        $bashir = $this->user('Bashir');
        $moulin = $this->user('Moulin');

        $request = $this->file($requester, [$ahsan, $bashir]);
        $this->actingAs($ahsan)->postJson(route('requests.forward', $request), ['to_user_id' => $moulin->id])->assertOk();

        $request->refresh()->load('recipients');
        $this->assertSame([$bashir->id, $moulin->id], $request->recipients->pluck('id')->sort()->values()->all());
    }
}
