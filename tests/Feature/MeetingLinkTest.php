<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\User;
use App\Services\Contracts\GoogleCalendarServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A booked video meeting must end up with a join link, or say why it did not.
 *
 * Google Calendar failures are non-fatal by design — booking cannot depend on a
 * third party being reachable — but the booking form used to promise "a Google
 * Meet link is generated automatically" with no way to supply one and no notice
 * when the promise failed.
 */
class MeetingLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['manage-meetings', 'view clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function organiser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        // The meetings list follows client visibility, not the meeting permission.
        $user->givePermissionTo(['manage-meetings', 'view clients']);

        return $user->fresh();
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    /** Stands in for Google being unreachable or unauthorised. */
    private function googleFails(): void
    {
        $this->mock(GoogleCalendarServiceInterface::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createEvent')->andReturn(null);
            $mock->shouldReceive('updateEvent')->andReturn(false);
            $mock->shouldReceive('cancelEvent')->andReturn(false);
            $mock->shouldReceive('deleteEvent')->andReturn(false);
        });
    }

    private function bookPayload(Client $client, array $overrides = []): array
    {
        return array_merge([
            'client_id'        => $client->id,
            'title'            => 'Project review',
            'type'             => 'video',
            'scheduled_at'     => now()->addWeek()->setTime(10, 0)->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
        ], $overrides);
    }

    public function test_a_link_typed_on_the_booking_form_is_stored(): void
    {
        $this->googleFails();
        $client = $this->client();

        $response = $this->actingAs($this->organiser())
            ->postJson(route('meetings.book.store'), $this->bookPayload($client, [
                'meeting_link' => 'https://meet.google.com/abc-defg-hij',
            ]))
            ->assertOk();

        $meeting = ClientMeeting::firstOrFail();

        $this->assertSame('https://meet.google.com/abc-defg-hij', $meeting->meeting_link);
        // join_url is what every screen and every email actually reads.
        $this->assertSame('https://meet.google.com/abc-defg-hij', $meeting->join_url);
        $this->assertNull($response->json('link_warning'));
    }

    public function test_a_video_meeting_with_no_link_at_all_warns_the_organiser(): void
    {
        $this->googleFails();
        $client = $this->client();

        $response = $this->actingAs($this->organiser())
            ->postJson(route('meetings.book.store'), $this->bookPayload($client))
            ->assertOk();

        // Booking still succeeds — Google being down must never block it.
        $this->assertDatabaseCount('client_meetings', 1);
        $this->assertNull(ClientMeeting::firstOrFail()->join_url);

        $this->assertNotNull($response->json('link_warning'));
        $this->assertStringContainsString('No join link', $response->json('link_warning'));
    }

    public function test_an_in_person_meeting_is_not_warned_about(): void
    {
        $this->googleFails();
        $client = $this->client();

        $response = $this->actingAs($this->organiser())
            ->postJson(route('meetings.book.store'), $this->bookPayload($client, ['type' => 'in_person']))
            ->assertOk();

        $this->assertNull($response->json('link_warning'));
    }

    public function test_a_google_meet_link_wins_over_a_typed_one(): void
    {
        $this->mock(GoogleCalendarServiceInterface::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createEvent')->andReturn([
                'event_id' => 'evt_123',
                'meet_url' => 'https://meet.google.com/generated-one',
            ]);
        });

        $client = $this->client();

        $response = $this->actingAs($this->organiser())
            ->postJson(route('meetings.book.store'), $this->bookPayload($client, [
                'meeting_link' => 'https://zoom.us/j/fallback',
            ]))
            ->assertOk();

        $meeting = ClientMeeting::firstOrFail();

        $this->assertSame('evt_123', $meeting->google_event_id);
        $this->assertSame('https://meet.google.com/generated-one', $meeting->join_url);
        $this->assertNull($response->json('link_warning'));
    }

    public function test_the_meetings_list_exposes_the_link_to_the_page(): void
    {
        $this->googleFails();
        $client = $this->client();
        $organiser = $this->organiser();

        $this->actingAs($organiser)->postJson(route('meetings.book.store'), $this->bookPayload($client, [
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        ]))->assertOk();

        $row = $this->actingAs($organiser)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('meetings.all'))
            ->assertOk()
            ->json('data.0');   // paginated response

        $this->assertSame('https://meet.google.com/abc-defg-hij', $row['join_url']);
        $this->assertSame('video', $row['type']);
    }

    public function test_a_bad_link_is_rejected(): void
    {
        $client = $this->client();

        $this->actingAs($this->organiser())
            ->postJson(route('meetings.book.store'), $this->bookPayload($client, [
                'meeting_link' => 'not-a-url',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('meeting_link');
    }
}
