<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Deleting and reacting to messages.
 *
 * The load-bearing rule: a retracted message is hidden from participants but
 * stays fully visible to chat monitors. Deletion is a flag, never a row
 * removal, so the record of what was actually said survives.
 */
class ChatModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'monitor chats', 'guard_name' => 'web']);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function sendMessage(User $from, User $to, string $body = 'the original text'): Message
    {
        $this->actingAs($from)->post(route('chat.send', $to), ['body' => $body])->assertOk();

        return Message::latest('id')->firstOrFail();
    }

    // ── Deleting ─────────────────────────────────────────────────────────

    public function test_a_sender_can_delete_their_own_message(): void
    {
        $me      = $this->user();
        $other   = $this->user();
        $message = $this->sendMessage($me, $other);

        $this->actingAs($me)
            ->deleteJson(route('chat.messages.destroy', $message))
            ->assertOk();

        $this->assertNotNull($message->fresh()->deleted_at);
        $this->assertSame($me->id, $message->fresh()->deleted_by);
    }

    public function test_deleting_keeps_the_row_and_the_original_text(): void
    {
        $me      = $this->user();
        $message = $this->sendMessage($me, $this->user());

        $this->actingAs($me)->deleteJson(route('chat.messages.destroy', $message))->assertOk();

        // The record must survive — monitoring depends on it.
        $this->assertDatabaseHas('messages', [
            'id'   => $message->id,
            'body' => 'the original text',
        ]);
    }

    public function test_you_cannot_delete_someone_elses_message(): void
    {
        $other   = $this->user();
        $message = $this->sendMessage($other, $this->user());

        $this->actingAs($this->user())
            ->deleteJson(route('chat.messages.destroy', $message))
            ->assertForbidden();

        $this->assertNull($message->fresh()->deleted_at);
    }

    public function test_deleting_twice_is_harmless(): void
    {
        $me      = $this->user();
        $message = $this->sendMessage($me, $this->user());

        $this->actingAs($me)->deleteJson(route('chat.messages.destroy', $message))->assertOk();
        $deletedAt = $message->fresh()->deleted_at;

        $this->actingAs($me)->deleteJson(route('chat.messages.destroy', $message))->assertOk();

        $this->assertEquals($deletedAt, $message->fresh()->deleted_at);
    }

    public function test_participants_see_a_placeholder_instead_of_the_text(): void
    {
        $me      = $this->user();
        $other   = $this->user();
        $message = $this->sendMessage($me, $other, 'something regrettable');

        $this->actingAs($me)->deleteJson(route('chat.messages.destroy', $message))->assertOk();

        $payload = $this->actingAs($other)
            ->getJson(route('chat.open', $me))
            ->assertOk()
            ->json('messages.0');

        $this->assertTrue($payload['deleted']);
        $this->assertNull($payload['body']);
    }

    public function test_a_monitor_still_sees_what_was_said(): void
    {
        $me      = $this->user();
        $other   = $this->user();
        $message = $this->sendMessage($me, $other, 'something regrettable');

        $this->actingAs($me)->deleteJson(route('chat.messages.destroy', $message))->assertOk();

        $payload = $this->actingAs($this->user('monitor chats'))
            ->getJson(route('chat.monitor.show', $message->conversation_id))
            ->assertOk()
            ->json('messages.0');

        $this->assertTrue($payload['deleted']);
        $this->assertSame('something regrettable', $payload['body']);
    }

    // ── Reacting ─────────────────────────────────────────────────────────

    public function test_a_participant_can_react_and_react_again_to_remove_it(): void
    {
        $me      = $this->user();
        $other   = $this->user();
        $message = $this->sendMessage($me, $other);

        $this->actingAs($other)
            ->postJson(route('chat.messages.react', $message), ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('reactions.0.emoji', '👍')
            ->assertJsonPath('reactions.0.count', 1);

        // Same emoji again toggles it off.
        $this->actingAs($other)
            ->postJson(route('chat.messages.react', $message), ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonCount(0, 'reactions');

        $this->assertDatabaseCount('message_reactions', 0);
    }

    public function test_two_people_reacting_with_the_same_emoji_are_counted_together(): void
    {
        $me      = $this->user();
        $other   = $this->user();
        $message = $this->sendMessage($me, $other);

        $this->actingAs($me)->postJson(route('chat.messages.react', $message), ['emoji' => '❤️'])->assertOk();

        $this->actingAs($other)
            ->postJson(route('chat.messages.react', $message), ['emoji' => '❤️'])
            ->assertOk()
            ->assertJsonPath('reactions.0.count', 2);
    }

    public function test_an_arbitrary_emoji_is_rejected(): void
    {
        $me      = $this->user();
        $message = $this->sendMessage($me, $this->user());

        $this->actingAs($me)
            ->postJson(route('chat.messages.react', $message), ['emoji' => '<script>x</script>'])
            ->assertStatus(422);

        $this->assertDatabaseCount('message_reactions', 0);
    }

    public function test_an_outsider_cannot_react(): void
    {
        $message = $this->sendMessage($this->user(), $this->user());

        $this->actingAs($this->user())
            ->postJson(route('chat.messages.react', $message), ['emoji' => '👍'])
            ->assertForbidden();
    }

    public function test_a_deleted_message_cannot_be_reacted_to(): void
    {
        $me      = $this->user();
        $other   = $this->user();
        $message = $this->sendMessage($me, $other);

        $this->actingAs($me)->deleteJson(route('chat.messages.destroy', $message))->assertOk();

        $this->actingAs($other)
            ->postJson(route('chat.messages.react', $message), ['emoji' => '👍'])
            ->assertStatus(422);
    }

    public function test_reactions_report_whether_they_are_yours(): void
    {
        $me      = $this->user();
        $other   = $this->user();
        $message = $this->sendMessage($me, $other);

        MessageReaction::create(['message_id' => $message->id, 'user_id' => $other->id, 'emoji' => '😂']);

        $mine = $this->actingAs($other)->getJson(route('chat.open', $me))->json('messages.0.reactions.0.mine');
        $theirs = $this->actingAs($me)->getJson(route('chat.open', $other))->json('messages.0.reactions.0.mine');

        $this->assertTrue($mine);
        $this->assertFalse($theirs);
    }
}
