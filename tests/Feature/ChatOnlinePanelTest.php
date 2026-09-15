<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The chat page's "Online now" panel.
 *
 * The list itself is live (the app-wide `online` presence channel, drawn in the
 * browser), so what can be pinned down here is the contract it stands on: what
 * presence tells other staff about a user, and that the page ships the panel.
 */
class ChatOnlinePanelTest extends TestCase
{
    use RefreshDatabase;

    /** The authorisation callback registered for a channel name in routes/channels.php. */
    private function channelCallback(string $name): callable
    {
        $callback = Broadcast::driver()->getChannels()->get($name);
        $this->assertNotNull($callback, "No '{$name}' channel is registered.");

        return $callback;
    }

    public function test_presence_names_the_user_and_their_role(): void
    {
        Role::firstOrCreate(['name' => 'Sales', 'guard_name' => 'web']);
        $user = tap(User::factory()->create(['is_active' => true, 'name' => 'Rahim Uddin']))->assignRole('Sales');

        $this->assertSame(
            ['id' => $user->id, 'name' => 'Rahim Uddin', 'role' => 'Sales'],
            $this->channelCallback('online')($user),
        );
    }

    /** Nothing beyond the directory basics goes out to every colleague. */
    public function test_presence_shares_nothing_private(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $payload = $this->channelCallback('online')($user);

        $this->assertSame(['id', 'name', 'role'], array_keys($payload));
        $this->assertNull($payload['role']);
    }

    public function test_the_chat_page_ships_the_panel_and_its_toggle(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('chat.index'))
            ->assertOk()
            ->assertSee('id="onlinePanel"', false)
            ->assertSee('id="onlineToggle"', false)
            ->assertSee('id="onlineList"', false)
            ->assertSee('Online now');
    }

    public function test_the_layout_keeps_a_roster_the_panel_can_read(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('chat.index'))
            ->assertSee('window.OnlineRoster = new Map();', false)
            ->assertSee('window.RealtimeReady =', false);
    }
}
