<?php

namespace Tests\Unit;

use App\Models\EmployeeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EmployeeRequest::respondBlockerFor() in isolation — the one rule shared by
 * the policy's authorization check and the controller's business-state
 * check. On a request sent to several people, "already answered" is a
 * per-recipient fact, not a request-wide one, until it's actually settled.
 */
class EmployeeRequestRespondBlockerTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function request(array $recipientStatuses, string $status = EmployeeRequest::STATUS_PENDING): EmployeeRequest
    {
        $request = EmployeeRequest::create([
            'subject' => 'x', 'message' => 'x', 'requested_by' => $this->user()->id, 'status' => $status,
        ]);

        foreach ($recipientStatuses as $user => $recipientStatus) {
            $request->recipients()->attach($user, ['status' => $recipientStatus]);
        }

        return $request->fresh(['recipients']);
    }

    public function test_a_recipient_who_has_not_answered_is_not_blocked(): void
    {
        $anika = $this->user();
        $request = $this->request([$anika->id => EmployeeRequest::STATUS_PENDING]);

        $this->assertNull($request->respondBlockerFor($anika));
    }

    public function test_a_recipient_who_already_answered_is_blocked_but_the_other_is_not(): void
    {
        $anika  = $this->user();
        $bashir = $this->user();
        $request = $this->request([
            $anika->id  => EmployeeRequest::STATUS_APPROVED,
            $bashir->id => EmployeeRequest::STATUS_PENDING,
        ]);

        $this->assertSame(
            'You already responded to this request — waiting on the other recipient(s).',
            $request->respondBlockerFor($anika),
        );
        $this->assertNull($request->respondBlockerFor($bashir));
    }

    public function test_a_request_already_rejected_is_blocked_for_everyone(): void
    {
        $anika  = $this->user();
        $bashir = $this->user();
        $request = $this->request([
            $anika->id  => EmployeeRequest::STATUS_REJECTED,
            $bashir->id => EmployeeRequest::STATUS_PENDING,
        ], EmployeeRequest::STATUS_REJECTED);

        $this->assertSame(
            'This request has already been rejected.',
            $request->respondBlockerFor($bashir),
        );
    }

    public function test_a_request_approved_by_everyone_is_blocked_for_everyone(): void
    {
        $anika  = $this->user();
        $bashir = $this->user();
        $request = $this->request([
            $anika->id  => EmployeeRequest::STATUS_APPROVED,
            $bashir->id => EmployeeRequest::STATUS_APPROVED,
        ], EmployeeRequest::STATUS_APPROVED);

        $this->assertSame(
            'This request has already been approved by everyone.',
            $request->respondBlockerFor($anika),
        );
    }
}
