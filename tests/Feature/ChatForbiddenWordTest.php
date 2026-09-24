<?php

namespace Tests\Feature;

use App\Models\ForbiddenWord;
use App\Models\Message;
use App\Models\User;
use App\Notifications\Chat\ForbiddenWordDetected;
use App\Notifications\Chat\ForbiddenWordUsedBySender;
use App\Services\Chat\ChatWordFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Using a forbidden word in the internal chat: the message still goes
 * through as normal (nothing is blocked), the sender is warned, and
 * whoever holds 'manage chat moderation' gets a summary.
 */
class ChatForbiddenWordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['monitor chats', 'manage chat moderation'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function send(User $from, User $to, string $body): void
    {
        $this->actingAs($from)->postJson(route('chat.send', $to), ['body' => $body])->assertOk();
    }

    public function test_a_message_with_a_forbidden_word_is_still_sent(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender = $this->user();
        $other  = $this->user('manage chat moderation');

        $this->send($sender, $other, 'this has a badword in it');

        $this->assertDatabaseHas('messages', ['body' => 'this has a badword in it']);
    }

    public function test_the_sender_is_warned(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender = $this->user();
        $other  = $this->user();

        $this->send($sender, $other, 'this has a badword in it');

        Notification::assertSentTo($sender, ForbiddenWordUsedBySender::class);
    }

    public function test_the_sender_is_told_which_word_matched_highlighted_in_red(): void
    {
        ForbiddenWord::create(['word' => 'sneakyword', 'is_active' => true]);
        $sender = $this->user();

        $this->send($sender, $this->user(), 'this has a sneakyword in it');

        Notification::assertSentTo($sender, function (ForbiddenWordUsedBySender $notification) use ($sender) {
            $payload = $notification->toDatabase($sender);

            return str_contains($payload['message'], 'sneakyword')
                && $payload['message_html'] === 'A message you just sent used a restricted word (<span class="notif-flagged-word">sneakyword</span>). Please keep it professional.';
        });
    }

    public function test_the_senders_notification_opens_the_chat_page_not_the_json_endpoint(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender = $this->user();
        $other  = $this->user();

        $this->send($sender, $other, 'this has a badword in it');

        Notification::assertSentTo($sender, function (ForbiddenWordUsedBySender $notification) use ($sender, $other) {
            $payload = $notification->toDatabase($sender);

            return $payload['url'] === route('chat.index', ['user' => $other->id]);
        });
    }

    public function test_the_moderator_notification_opens_the_monitor_page_not_the_json_endpoint(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender    = $this->user();
        $moderator = $this->user('manage chat moderation');

        $this->send($sender, $this->user(), 'this has a badword in it');

        Notification::assertSentTo($moderator, function (ForbiddenWordDetected $notification) use ($moderator) {
            $payload = $notification->toDatabase($moderator);
            $conversation = \App\Models\Conversation::latest('id')->firstOrFail();

            return $payload['url'] === route('chat.monitor', ['conversation' => $conversation->id]);
        });
    }

    public function test_someone_holding_the_moderation_permission_gets_a_summary(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender    = $this->user();
        $moderator = $this->user('manage chat moderation');

        $this->send($sender, $this->user(), 'this has a badword in it');

        Notification::assertSentTo($moderator, function (ForbiddenWordDetected $notification) use ($moderator, $sender) {
            $payload = $notification->toDatabase($moderator);

            return str_contains($payload['message'], $sender->name)
                && str_contains($payload['message'], 'badword')
                && str_contains($payload['message'], 'this has a badword in it');
        });
    }

    public function test_someone_without_the_permission_is_not_notified(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $bystander = $this->user(); // active, but no moderation permission

        $this->send($this->user(), $this->user(), 'this has a badword in it');

        Notification::assertNotSentTo($bystander, ForbiddenWordDetected::class);
    }

    public function test_the_sender_is_not_notified_of_their_own_violation_as_a_moderator(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender = $this->user('manage chat moderation');

        $this->send($sender, $this->user(), 'this has a badword in it');

        Notification::assertNotSentTo($sender, ForbiddenWordDetected::class);
    }

    public function test_a_clean_message_notifies_nobody(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender    = $this->user();
        $moderator = $this->user('manage chat moderation');

        $this->send($sender, $this->user(), 'a perfectly ordinary message');

        Notification::assertNothingSentTo($sender);
        Notification::assertNothingSentTo($moderator);
    }

    public function test_it_also_applies_to_group_messages(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender    = $this->user();
        $moderator = $this->user('manage chat moderation');
        $member    = $this->user();

        $group = app(\App\Services\ChatService::class)->createGroup($sender, 'Team', [$member->id]);

        $this->actingAs($sender)
            ->postJson(route('chat.groups.send', $group), ['body' => 'this has a badword in it'])
            ->assertOk();

        Notification::assertSentTo($sender, ForbiddenWordUsedBySender::class);
        Notification::assertSentTo($moderator, ForbiddenWordDetected::class);
    }

    public function test_the_violation_is_logged(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);

        $this->send($this->user(), $this->user(), 'this has a badword in it');

        $this->assertDatabaseHas('activity_logs', ['module' => 'Chat', 'action' => 'Forbidden Word Used']);
    }

    public function test_a_flagged_message_reports_flagged_true_and_a_clean_one_false(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender = $this->user();
        $other  = $this->user();

        $response = $this->actingAs($sender)->postJson(route('chat.send', $other), ['body' => 'this has a BadWord in it'])->assertOk();
        $this->assertTrue($response->json('message.flagged'));

        // The same flag is what a recipient (or the sender's other tab) sees
        // on the initial page load, not only on the send response.
        $this->actingAs($other)->getJson(route('chat.open', $sender))
            ->assertOk()
            ->assertJsonPath('messages.0.flagged', true);

        $clean = $this->actingAs($sender)->postJson(route('chat.send', $other), ['body' => 'a perfectly ordinary message'])->assertOk();
        $this->assertFalse($clean->json('message.flagged'));
    }

    /**
     * The word itself is never shown in the ordinary chat (an icon with a
     * generic notice instead — see msg-flag-icon in chat/index.blade.php),
     * but body_html still carries the highlighted word for the moderator's
     * monitor view, which does show exactly what was flagged.
     */
    public function test_body_html_still_carries_the_highlighted_word_for_the_monitor_view(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender = $this->user();
        $other  = $this->user();

        $response = $this->actingAs($sender)->postJson(route('chat.send', $other), ['body' => 'this has a BadWord in it'])->assertOk();

        $this->assertSame(
            'this has a <mark class="chat-flagged-word">BadWord</mark> in it',
            $response->json('message.body_html'),
        );
    }

    public function test_the_live_broadcast_also_carries_the_flag_and_the_highlighted_word(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender = $this->user();
        $other  = $this->user();

        $message = app(\App\Services\ChatService::class)->sendMessage(
            \App\Models\Conversation::between($sender->id, $other->id), $sender, 'this has a badword in it',
        );

        $payload = (new \App\Events\MessageSent($message->fresh(), [$other->id]))->broadcastWith();

        $this->assertTrue($payload['flagged']);
        $this->assertSame(
            'this has a <mark class="chat-flagged-word">badword</mark> in it',
            $payload['body_html'],
        );
    }

    public function test_the_notification_links_land_on_real_pages_not_json(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender    = $this->user();
        $other     = $this->user();
        $moderator = $this->user('manage chat moderation', 'monitor chats');

        $this->send($sender, $other, 'this has a badword in it');

        // The sender's own notification: chat.index, not the chat.open AJAX
        // endpoint (which returns raw JSON — the reported bug).
        $senderUrl = route('chat.index', ['user' => $other->id]);
        $this->actingAs($sender)->get($senderUrl)->assertOk()->assertViewIs('chat.index');

        // The moderator's notification: the monitor page, not chat.monitor.show.
        $conversation = \App\Models\Conversation::latest('id')->firstOrFail();
        $moderatorUrl = route('chat.monitor', ['conversation' => $conversation->id]);
        $this->actingAs($moderator)->get($moderatorUrl)->assertOk()->assertViewIs('chat.monitor');
    }

    public function test_the_chat_page_is_set_up_for_a_flag_icon_not_a_highlighted_word(): void
    {
        $this->actingAs($this->user())->get(route('chat.index'))
            ->assertOk()
            ->assertSee('msg-flag-icon', false)
            ->assertSee('bi-exclamation-triangle-fill', false)
            // Not the CSS rule that colors the word itself red — only the
            // monitor view still defines that class (see the next test).
            ->assertDontSee('.chat-flagged-word {', false);
    }

    public function test_the_monitor_page_still_highlights_the_word_itself(): void
    {
        $this->actingAs($this->user('monitor chats'))->get(route('chat.monitor'))
            ->assertOk()
            ->assertSee('chat-flagged-word', false);
    }

    public function test_a_matching_message_still_broadcasts_and_is_readable_normally(): void
    {
        // Moderation must never interfere with the ordinary send path — it
        // only ever adds a side effect, never changes the response.
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $sender = $this->user();
        $other  = $this->user();

        $this->send($sender, $other, 'this has a badword in it');

        $message = Message::latest('id')->firstOrFail();
        $this->actingAs($other)->getJson(route('chat.open', $sender))
            ->assertOk()
            ->assertJsonPath('messages.0.id', $message->id)
            ->assertJsonPath('messages.0.body', 'this has a badword in it');
    }
}
