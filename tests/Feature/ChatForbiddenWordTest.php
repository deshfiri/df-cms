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

    public function test_the_sender_is_not_told_which_word_matched(): void
    {
        ForbiddenWord::create(['word' => 'sneakyword', 'is_active' => true]);
        $sender = $this->user();

        $this->send($sender, $this->user(), 'this has a sneakyword in it');

        Notification::assertSentTo($sender, function (ForbiddenWordUsedBySender $notification) use ($sender) {
            $payload = $notification->toDatabase($sender);

            return !str_contains($payload['message'], 'sneakyword');
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
