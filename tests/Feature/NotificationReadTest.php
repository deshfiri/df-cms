<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The bell: what it counts, and what reading one does.
 *
 * The count drives the unread badge and the tab title, so it has to fall the
 * moment a notification is opened — and never for anyone else's.
 */
class NotificationReadTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, string $title = 'A task for you', string $type = 'App\\Notifications\\TaskAssigned'): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id'   => $id,
            'type' => $type,
            'data' => ['title' => $title, 'message' => 'Have a look', 'url' => '/tasks/1'],
        ]);

        return $id;
    }

    public function test_the_bell_reports_what_is_unread(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->notify($user);
        $read = $this->notify($user, 'Older');
        $user->notifications()->find($read)->markAsRead();

        $this->actingAs($user)->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('notifications.0.read', false)
            ->assertJsonPath('notifications.1.read', true);
    }

    public function test_opening_one_marks_it_read(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $id   = $this->notify($user);

        $this->actingAs($user)->postJson(route('notifications.read', $id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($user->notifications()->find($id)->read_at);
        $this->actingAs($user)->getJson(route('notifications.index'))->assertJsonPath('unread_count', 0);
    }

    public function test_reading_the_same_one_twice_is_harmless(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $id   = $this->notify($user);

        $this->actingAs($user)->postJson(route('notifications.read', $id))->assertOk();
        $readAt = $user->notifications()->find($id)->read_at;

        $this->actingAs($user)->postJson(route('notifications.read', $id))->assertOk();

        $this->assertEquals($readAt, $user->notifications()->find($id)->read_at);
    }

    public function test_you_can_only_read_your_own(): void
    {
        $owner     = User::factory()->create(['is_active' => true]);
        $stranger  = User::factory()->create(['is_active' => true]);
        $id        = $this->notify($owner);

        $this->actingAs($stranger)->postJson(route('notifications.read', $id))->assertNotFound();

        $this->assertNull($owner->notifications()->find($id)->read_at);
        $this->actingAs($owner)->getJson(route('notifications.index'))->assertJsonPath('unread_count', 1);
    }

    public function test_clearing_them_all_clears_only_yours(): void
    {
        $user  = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $this->notify($user);
        $this->notify($user);
        $theirs = $this->notify($other);

        $this->actingAs($user)->postJson(route('notifications.read-all'))->assertOk();

        $this->actingAs($user)->getJson(route('notifications.index'))->assertJsonPath('unread_count', 0);
        $this->assertNull($other->notifications()->find($theirs)->read_at);
    }

    public function test_message_html_is_exposed_when_a_notification_type_sets_it_and_null_otherwise(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->notify($user); // no message_html in its data
        $user->notifications()->create([
            'id'   => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Chat\\ForbiddenWordUsedBySender',
            'data' => ['title' => 'Message flagged', 'message' => 'plain', 'message_html' => '<span class="notif-flagged-word">badword</span>', 'url' => '/chat'],
        ]);

        $response = $this->actingAs($user)->getJson(route('notifications.index'))->assertOk();
        $notifications = collect($response->json('notifications'));

        $this->assertNull($notifications->firstWhere('title', 'A task for you')['message_html']);
        $this->assertSame(
            '<span class="notif-flagged-word">badword</span>',
            $notifications->firstWhere('title', 'Message flagged')['message_html'],
        );
    }

    public function test_a_guest_gets_nothing(): void
    {
        // The endpoints are per-user, so a guest gets bounced rather than a count.
        $this->getJson(route('notifications.index'))->assertUnauthorized();
        $this->postJson(route('notifications.read', 'anything'))->assertUnauthorized();
    }
}
