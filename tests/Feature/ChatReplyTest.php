<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Replying to one specific earlier message.
 *
 * The rule worth guarding: a quote may only ever point at a message in the
 * same conversation. Without that, quoting an arbitrary id would pull another
 * thread's text into this one.
 */
class ChatReplyTest extends TestCase
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

    private function sendMessage(User $from, User $to, array $payload = []): Message
    {
        $this->actingAs($from)
            ->postJson(route('chat.send', $to), $payload + ['body' => 'the original text'])
            ->assertOk();

        return Message::latest('id')->firstOrFail();
    }

    // ── Sending a reply ──────────────────────────────────────────────────

    public function test_a_message_can_quote_an_earlier_one(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $original = $this->sendMessage($other, $me, ['body' => 'third message']);

        $response = $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'answering that one',
            'reply_to_id' => $original->id,
        ])->assertOk();

        $this->assertSame($original->id, Message::latest('id')->first()->reply_to_id);

        $response->assertJsonPath('message.reply_to.id', $original->id);
        $response->assertJsonPath('message.reply_to.preview', 'third message');
        $response->assertJsonPath('message.reply_to.sender_name', $other->name);
        // The viewer did not write the quoted message, so it is not "yours".
        $response->assertJsonPath('message.reply_to.mine', false);
    }

    public function test_a_message_without_a_reply_reports_no_quote(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($me)
            ->postJson(route('chat.send', $other), ['body' => 'just a message'])
            ->assertOk()
            ->assertJsonPath('message.reply_to', null);
    }

    public function test_you_can_reply_to_your_own_message(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $mine = $this->sendMessage($me, $other, ['body' => 'my earlier point']);

        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'adding to this',
            'reply_to_id' => $mine->id,
        ])->assertOk()->assertJsonPath('message.reply_to.mine', true);
    }

    // ── Multiline bodies ─────────────────────────────────────────────────

    public function test_a_multiline_message_keeps_its_line_breaks(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $body = "First line\nSecond line\n\nAfter a blank one";

        $this->actingAs($me)
            ->postJson(route('chat.send', $other), ['body' => $body])
            ->assertOk()
            // Stored and returned exactly as typed.
            ->assertJsonPath('message.body', $body);

        $this->assertSame($body, Message::latest('id')->firstOrFail()->body);
    }

    /**
     * The thread shows the breaks; the conversation list and a reply's quote do
     * not — both are single-line and would otherwise inherit ragged gaps.
     */
    public function test_previews_collapse_a_multiline_body_to_one_line(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $original = $this->sendMessage($other, $me, ['body' => "Line one\n\nLine two"]);

        $this->assertSame('Line one Line two', $original->previewLine());

        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'answering',
            'reply_to_id' => $original->id,
        ])->assertOk()->assertJsonPath('message.reply_to.preview', 'Line one Line two');

        // ...while the conversation list agrees.
        $this->actingAs($other)->getJson(route('chat.conversations'))
            ->assertOk()
            ->assertJsonPath('conversations.0.last_body', 'answering');
    }

    // ── The boundary that matters ────────────────────────────────────────

    public function test_a_message_from_another_conversation_cannot_be_quoted(): void
    {
        $me       = $this->user();
        $other    = $this->user();
        $stranger = $this->user();

        // A message in a conversation the replier is not part of at all.
        $foreign = $this->sendMessage($stranger, $other, ['body' => 'private to them']);

        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'sneaking a quote',
            'reply_to_id' => $foreign->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('messages', ['body' => 'sneaking a quote']);
    }

    public function test_a_message_from_the_senders_other_conversation_cannot_be_quoted(): void
    {
        $me    = $this->user();
        $other = $this->user();
        $third = $this->user();

        // The replier wrote this one, but in a different thread.
        $elsewhere = $this->sendMessage($me, $third, ['body' => 'said to someone else']);

        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'cross-thread quote',
            'reply_to_id' => $elsewhere->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('messages', ['body' => 'cross-thread quote']);
    }

    public function test_quoting_a_message_that_does_not_exist_is_rejected(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'ghost quote',
            'reply_to_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('reply_to_id');
    }

    // ── Rendering the quote back ─────────────────────────────────────────

    public function test_the_thread_returns_the_quote_with_each_reply(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $original = $this->sendMessage($other, $me, ['body' => 'the one being answered']);
        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'my answer',
            'reply_to_id' => $original->id,
        ])->assertOk();

        $this->actingAs($me)
            ->getJson(route('chat.open', $other))
            ->assertOk()
            ->assertJsonPath('messages.1.reply_to.id', $original->id)
            ->assertJsonPath('messages.1.reply_to.preview', 'the one being answered')
            ->assertJsonPath('messages.0.reply_to', null);
    }

    /**
     * A retracted message keeps its place in the quote. Dropping it would leave
     * the reply answering nothing, and the body must not leak either.
     */
    public function test_a_quote_of_a_retracted_message_is_redacted_but_kept(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $original = $this->sendMessage($other, $me, ['body' => 'sensitive original']);
        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'my answer',
            'reply_to_id' => $original->id,
        ])->assertOk();

        $this->actingAs($other)->deleteJson(route('chat.messages.destroy', $original))->assertOk();

        $response = $this->actingAs($me)->getJson(route('chat.open', $other))->assertOk();

        $response->assertJsonPath('messages.1.reply_to.id', $original->id);
        $response->assertJsonPath('messages.1.reply_to.deleted', true);
        $response->assertJsonPath('messages.1.reply_to.preview', 'This message was deleted');
        $response->assertDontSee('sensitive original');
    }

    public function test_an_attachment_only_message_is_quoted_by_what_it_is(): void
    {
        Storage::fake('local');

        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($other)->post(route('chat.send', $me), [
            'file' => UploadedFile::fake()->image('holiday.jpg'),
        ])->assertOk();

        $original = Message::latest('id')->firstOrFail();

        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'nice one',
            'reply_to_id' => $original->id,
        ])->assertOk()->assertJsonPath('message.reply_to.preview', '📷 Photo');
    }

    // ── Monitoring ───────────────────────────────────────────────────────

    public function test_a_monitor_sees_the_quote_and_the_real_text_behind_it(): void
    {
        $me      = $this->user();
        $other   = $this->user();
        $monitor = $this->user('monitor chats');

        $original = $this->sendMessage($other, $me, ['body' => 'sensitive original']);
        $this->actingAs($me)->postJson(route('chat.send', $other), [
            'body'        => 'my answer',
            'reply_to_id' => $original->id,
        ])->assertOk();

        $this->actingAs($other)->deleteJson(route('chat.messages.destroy', $original))->assertOk();

        $conversation = Conversation::firstOrFail();

        $this->actingAs($monitor)
            ->getJson(route('chat.monitor.show', $conversation))
            ->assertOk()
            // The message itself is unredacted for a monitor...
            ->assertJsonPath('messages.0.body', 'sensitive original')
            // ...while the quote still reports it as retracted.
            ->assertJsonPath('messages.1.reply_to.id', $original->id)
            ->assertJsonPath('messages.1.reply_to.deleted', true);
    }
}
