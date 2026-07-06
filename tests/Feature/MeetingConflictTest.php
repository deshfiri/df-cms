<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MeetingConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manage-meetings', 'manage clients'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        // Manager bypasses ChangeApprovalService — these tests are about
        // meeting-conflict logic, not the pending-change approval gate
        // (already covered by ChangeApprovalTest).
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
    }

    private function makeClient(): Client
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'Test Client',
            'brand_name'  => 'Test Brand',
            'category_id' => $category->id,
        ]);
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Manager');
        $user->givePermissionTo(['manage-meetings', 'manage clients']);

        return $user;
    }

    public function test_the_same_staff_member_cannot_be_double_booked_across_two_different_clients(): void
    {
        $actor    = $this->makeUser();
        $staff    = $this->makeUser();
        $clientA  = $this->makeClient();
        $clientB  = $this->makeClient();
        $time     = now()->addDay()->setTime(10, 0);

        ClientMeeting::create([
            'client_id'        => $clientA->id,
            'title'            => 'First booking',
            'type'             => 'in_person',
            'status'           => 'Scheduled',
            'scheduled_at'     => $time,
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
            'created_by'       => $actor->id,
        ]);

        $response = $this->actingAs($actor)->postJson(route('clients.meetings.store', $clientB), [
            'title'            => 'Overlapping booking, different client',
            'type'             => 'in_person',
            'scheduled_at'     => $time->copy()->addMinutes(30)->toDateTimeString(),
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, ClientMeeting::count());
    }

    public function test_rescheduling_a_meeting_into_an_already_occupied_slot_is_blocked(): void
    {
        $actor   = $this->makeUser();
        $staff   = $this->makeUser();
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();
        $time    = now()->addDay()->setTime(10, 0);

        ClientMeeting::create([
            'client_id'        => $clientA->id,
            'title'            => 'Fixed meeting',
            'type'             => 'in_person',
            'status'           => 'Scheduled',
            'scheduled_at'     => $time,
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
            'created_by'       => $actor->id,
        ]);

        $movable = ClientMeeting::create([
            'client_id'        => $clientB->id,
            'title'            => 'Movable meeting',
            'type'             => 'in_person',
            'status'           => 'Scheduled',
            'scheduled_at'     => $time->copy()->addHours(3),
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
            'created_by'       => $actor->id,
        ]);

        $response = $this->actingAs($actor)->putJson(route('clients.meetings.update', [$clientB, $movable]), [
            'title'            => 'Movable meeting',
            'type'             => 'in_person',
            'scheduled_at'     => $time->toDateTimeString(),
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame($time->copy()->addHours(3)->toDateTimeString(), $movable->fresh()->scheduled_at->toDateTimeString());
    }

    public function test_a_rescheduled_meeting_still_blocks_new_bookings_at_its_new_time(): void
    {
        $actor   = $this->makeUser();
        $staff   = $this->makeUser();
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();
        $time    = now()->addDay()->setTime(10, 0);

        $meeting = ClientMeeting::create([
            'client_id'        => $clientA->id,
            'title'            => 'Originally elsewhere',
            'type'             => 'in_person',
            'status'           => 'Scheduled',
            'scheduled_at'     => $time->copy()->addHours(5),
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
            'created_by'       => $actor->id,
        ]);

        // Move it onto the slot we're about to test against.
        $reschedule = $this->actingAs($actor)->putJson(route('clients.meetings.update', [$clientA, $meeting]), [
            'title'            => 'Originally elsewhere',
            'type'             => 'in_person',
            'scheduled_at'     => $time->toDateTimeString(),
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
        ]);
        $reschedule->assertOk();
        $this->assertSame('Rescheduled', $meeting->fresh()->status);

        // A brand new booking for a different client at the same (now-rescheduled) time must still conflict.
        $response = $this->actingAs($actor)->postJson(route('clients.meetings.store', $clientB), [
            'title'            => 'Should conflict with the rescheduled meeting',
            'type'             => 'in_person',
            'scheduled_at'     => $time->toDateTimeString(),
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_availability_slots_exclude_a_rescheduled_meetings_new_time(): void
    {
        $actor = $this->makeUser();
        $staff = $this->makeUser();
        $client = $this->makeClient();
        $date = now()->addDay()->toDateString();

        $meeting = ClientMeeting::create([
            'client_id'        => $client->id,
            'title'            => 'Will be moved',
            'type'             => 'in_person',
            'status'           => 'Scheduled',
            'scheduled_at'     => $date . ' 14:00:00',
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
            'created_by'       => $actor->id,
        ]);

        $this->actingAs($actor)->putJson(route('clients.meetings.update', [$client, $meeting]), [
            'title'            => 'Will be moved',
            'type'             => 'in_person',
            'scheduled_at'     => $date . ' 10:00:00',
            'duration_minutes' => 60,
            'assigned_to'      => $staff->id,
        ])->assertOk();

        $response = $this->actingAs($actor)->postJson(route('meetings.availability'), [
            'assigned_to'      => $staff->id,
            'date'             => $date,
            'duration_minutes' => 60,
        ]);

        $response->assertOk();
        $slot10 = collect($response->json('slots'))->firstWhere('time', '10:00');
        $slot14 = collect($response->json('slots'))->firstWhere('time', '14:00');

        $this->assertFalse($slot10['available']);
        $this->assertTrue($slot14['available']);
    }
}
