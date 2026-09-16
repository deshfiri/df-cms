<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Group chats.
 *
 * Only roles granted "create chat groups" may start one. After that the group
 * governs itself: members read and write, the owner and admins rename it and
 * change who is in it, and each member keeps their own read marker — reading a
 * group never marks it read for anybody else.
 */
class ChatGroupTest extends TestCase
{
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'create chat groups', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'monitor chats', 'guard_name' => 'web']);
        $this->chat = app(ChatService::class);
    }

    private function user(string $name, string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user->fresh();
    }

    /** @return array{0:Conversation,1:User,2:User,3:User} group, owner, member A, member B */
    private function designTeam(): array
    {
        $owner = $this->user('Owner', 'create chat groups');
        $a     = $this->user('Anika');
        $b     = $this->user('Bashir');

        return [$this->chat->createGroup($owner, 'Design Team', [$a->id, $b->id]), $owner, $a, $b];
    }

    private function say(Conversation $group, User $who, string $body)
    {
        return $this->actingAs($who)->postJson(route('chat.groups.send', $group), ['body' => $body]);
    }

    // ── Creating ─────────────────────────────────────────────────────────

    public function test_an_authorized_role_can_create_a_group(): void
    {
        $owner = $this->user('Owner', 'create chat groups');
        $a     = $this->user('Anika');
        $b     = $this->user('Bashir');

        $this->actingAs($owner)
            ->postJson(route('chat.groups.store'), ['name' => 'Design Team', 'member_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('group.name', 'Design Team')
            ->assertJsonPath('group.my_role', 'owner')
            ->assertJsonCount(3, 'group.members');

        $group = Conversation::where('type', 'group')->sole();
        $this->assertSame('owner', $group->roleOf($owner));
        $this->assertSame('member', $group->roleOf($a));
        $this->assertNull($group->user_one_id);
    }

    public function test_everyone_else_is_refused(): void
    {
        $plain = $this->user('Plain');
        $a     = $this->user('Anika');

        $this->actingAs($plain)
            ->postJson(route('chat.groups.store'), ['name' => 'Rogue', 'member_ids' => [$a->id]])
            ->assertForbidden();

        $this->assertSame(0, Conversation::where('type', 'group')->count());
    }

    public function test_a_group_needs_a_name_and_somebody_in_it(): void
    {
        $owner = $this->user('Owner', 'create chat groups');

        $this->actingAs($owner)
            ->postJson(route('chat.groups.store'), ['name' => '', 'member_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'member_ids']);
    }

    public function test_inactive_staff_cannot_be_added(): void
    {
        $owner    = $this->user('Owner', 'create chat groups');
        $inactive = User::factory()->create(['is_active' => false]);

        $this->actingAs($owner)
            ->postJson(route('chat.groups.store'), ['name' => 'Team', 'member_ids' => [$inactive->id]])
            ->assertStatus(422);
    }

    // ── Seeing and talking ───────────────────────────────────────────────

    public function test_a_new_group_is_listed_for_its_members_before_anyone_speaks(): void
    {
        [$group, $owner, $a] = $this->designTeam();
        $outsider = $this->user('Outsider');

        $this->actingAs($a)->getJson(route('chat.conversations'))
            ->assertOk()
            ->assertJsonPath('conversations.0.conversation_id', $group->id)
            ->assertJsonPath('conversations.0.is_group', true)
            ->assertJsonPath('conversations.0.name', 'Design Team')
            ->assertJsonPath('conversations.0.member_count', 3);

        $this->actingAs($outsider)->getJson(route('chat.conversations'))
            ->assertJsonCount(0, 'conversations');
    }

    public function test_members_can_talk_and_everyone_else_hears_it(): void
    {
        Event::fake([MessageSent::class]);
        [$group, $owner, $a, $b] = $this->designTeam();

        $this->say($group, $a, 'Draft is up')->assertOk()->assertJsonPath('message.body', 'Draft is up');

        Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($owner, $b, $a) {
            sort($e->recipientIds);
            $expected = [$owner->id, $b->id];
            sort($expected);

            return $e->recipientIds === $expected && !in_array($a->id, $e->recipientIds, true);
        });
    }

    public function test_an_outsider_can_neither_read_nor_write(): void
    {
        [$group] = $this->designTeam();
        $outsider = $this->user('Outsider');

        $this->actingAs($outsider)->getJson(route('chat.groups.show', $group))->assertForbidden();
        $this->say($group, $outsider, 'Let me in')->assertForbidden();
    }

    public function test_the_realtime_channel_admits_members_only(): void
    {
        [$group, $owner, $a] = $this->designTeam();
        $outsider = $this->user('Outsider');

        $authorize = Broadcast::driver()->getChannels()->get('conversation.{conversationId}');

        $this->assertTrue($authorize($a, $group->id));
        $this->assertFalse($authorize($outsider, $group->id));
    }

    // ── Read state is per member ─────────────────────────────────────────

    public function test_reading_a_group_only_marks_it_read_for_you(): void
    {
        [$group, $owner, $a, $b] = $this->designTeam();
        $this->say($group, $a, 'One');
        $this->say($group, $a, 'Two');

        $this->assertSame(0, $this->chat->unreadCountFor($a), 'Your own messages are never unread for you.');
        $this->assertSame(2, $this->chat->unreadCountFor($owner));
        $this->assertSame(2, $this->chat->unreadCountFor($b));

        $this->actingAs($b)->getJson(route('chat.groups.show', $group))->assertOk()->assertJsonCount(2, 'messages');

        $this->assertSame(0, $this->chat->unreadCountFor($b->fresh()));
        $this->assertSame(2, $this->chat->unreadCountFor($owner), "Bashir reading it must not mark it read for the owner.");
    }

    public function test_someone_added_later_does_not_inherit_a_backlog_of_unread(): void
    {
        [$group, $owner, $a] = $this->designTeam();
        $this->say($group, $a, 'Before you joined');

        $late = $this->user('Late');
        $this->actingAs($owner)
            ->postJson(route('chat.groups.members.add', $group), ['member_ids' => [$late->id]])
            ->assertOk()
            ->assertJsonCount(4, 'group.members');

        $this->assertSame(0, $this->chat->unreadCountFor($late));

        $this->say($group, $a, 'After you joined');
        $this->assertSame(1, $this->chat->unreadCountFor($late));
    }

    // ── Running the group ────────────────────────────────────────────────

    public function test_only_the_owner_or_an_admin_can_change_the_group(): void
    {
        [$group, $owner, $a, $b] = $this->designTeam();

        $this->actingAs($a)->putJson(route('chat.groups.update', $group), ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($a)->deleteJson(route('chat.groups.members.remove', [$group, $b]))->assertForbidden();

        $this->actingAs($owner)->putJson(route('chat.groups.update', $group), ['name' => 'Brand Team'])
            ->assertOk()->assertJsonPath('group.name', 'Brand Team');

        $this->actingAs($owner)->deleteJson(route('chat.groups.members.remove', [$group, $b]))
            ->assertOk()->assertJsonCount(2, 'group.members');

        $this->assertFalse($group->fresh()->hasParticipant($b));
    }

    public function test_the_owner_cannot_be_removed(): void
    {
        [$group, $owner, $a] = $this->designTeam();
        $group->members()->updateExistingPivot($a->id, ['role' => 'admin']);

        $this->actingAs($a)->deleteJson(route('chat.groups.members.remove', [$group, $owner]))->assertStatus(422);
        $this->assertTrue($group->fresh()->hasParticipant($owner));
    }

    public function test_a_member_can_leave(): void
    {
        [$group, $owner, $a] = $this->designTeam();

        $this->actingAs($a)->postJson(route('chat.groups.leave', $group))->assertOk();

        $this->assertFalse($group->fresh()->hasParticipant($a));
        $this->actingAs($a)->getJson(route('chat.groups.show', $group))->assertForbidden();
    }

    public function test_an_owner_who_leaves_hands_the_group_on(): void
    {
        [$group, $owner, $a, $b] = $this->designTeam();

        $this->actingAs($owner)->postJson(route('chat.groups.leave', $group))->assertOk();

        $group = $group->fresh();
        $this->assertFalse($group->hasParticipant($owner));
        $this->assertSame('owner', $group->roleOf($a), 'The longest-standing member takes over.');
        $this->assertTrue($group->canBeManagedBy($a));
    }

    // ── The chat page ────────────────────────────────────────────────────

    public function test_the_new_group_button_is_only_offered_to_those_who_may_create_groups(): void
    {
        $this->actingAs($this->user('Plain'))->get(route('chat.index'))
            ->assertOk()
            ->assertDontSee('id="newGroupBtn"', false)
            ->assertDontSee('id="newGroupModal"', false);

        $this->actingAs($this->user('Lead', 'create chat groups'))->get(route('chat.index'))
            ->assertOk()
            ->assertSee('id="newGroupBtn"', false)
            ->assertSee('id="newGroupModal"', false);
    }

    public function test_a_group_message_event_names_the_group_for_toasts(): void
    {
        [$group, $owner, $a] = $this->designTeam();
        $message = $this->chat->sendMessage($group, $a, 'Ping');

        $payload = (new MessageSent($message->fresh(), [$owner->id]))->broadcastWith();

        $this->assertTrue($payload['is_group']);
        $this->assertSame('Design Team', $payload['conversation_name']);
    }

    // ── Monitoring and 1:1s ──────────────────────────────────────────────

    public function test_the_chat_monitor_names_groups(): void
    {
        [$group, $owner, $a] = $this->designTeam();
        $this->say($group, $a, 'Hello team');
        $monitor = $this->user('Monitor', 'monitor chats');

        $this->actingAs($monitor)->getJson(route('chat.monitor.conversations'))
            ->assertOk()
            ->assertJsonPath('conversations.0.user_one', '👥 Design Team')
            ->assertJsonPath('conversations.0.user_two', '3 members');
    }

    public function test_one_to_one_chat_is_unchanged(): void
    {
        $a = $this->user('Anika');
        $b = $this->user('Bashir');

        $this->actingAs($a)->postJson(route('chat.send', $b), ['body' => 'Hi'])->assertOk();

        $this->assertSame(1, $this->chat->unreadCountFor($b));
        $this->actingAs($b)->getJson(route('chat.open', $a))->assertOk();
        $this->assertSame(0, $this->chat->unreadCountFor($b));

        $this->actingAs($b)->getJson(route('chat.conversations'))
            ->assertJsonPath('conversations.0.is_group', false)
            ->assertJsonPath('conversations.0.user_id', $a->id);
    }
}
