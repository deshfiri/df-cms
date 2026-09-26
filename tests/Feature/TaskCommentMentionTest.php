<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCommentMention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A task's discussion may only @mention people actually party to it — its
 * assignee(s) and whoever created it — not anyone in the system. Commenting
 * itself notifies no one; only being named does.
 */
class TaskCommentMentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permission::firstOrCreate(['name' => 'view tasks', 'guard_name' => 'web']);
    }

    private function user(string $name = 'User'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->givePermissionTo('view tasks');

        return $user->fresh();
    }

    private function task(User $assignee, User $creator): Task
    {
        $task = Task::create([
            'title' => 'Write the onboarding doc', 'priority' => 'Medium', 'status' => 'Pending',
            'type' => 'Other', 'created_by' => $creator->id,
        ]);
        $task->assignees()->sync([$assignee->id]);

        return $task;
    }

    public function test_the_assignee_mentioning_the_creator_notifies_them(): void
    {
        Notification::fake();
        $creator  = $this->user('Rahim Creator');
        $assignee = $this->user('Karim Assignee');
        $task     = $this->task($assignee, $creator);

        $this->actingAs($assignee)->postJson(route('tasks.comments.store', $task), [
            'comment' => 'Almost done, @Rahim Creator can you check the outline?',
        ])->assertOk();

        Notification::assertSentTo($creator, TaskCommentMention::class);
    }

    public function test_the_creator_mentioning_the_assignee_notifies_them(): void
    {
        Notification::fake();
        $creator  = $this->user('Rahim Creator');
        $assignee = $this->user('Karim Assignee');
        $task     = $this->task($assignee, $creator);

        $this->actingAs($creator)->postJson(route('tasks.comments.store', $task), [
            'comment' => '@Karim Assignee please prioritize this today',
        ])->assertOk();

        Notification::assertSentTo($assignee, TaskCommentMention::class);
    }

    public function test_mentioning_yourself_does_not_notify_you(): void
    {
        Notification::fake();
        $creator  = $this->user('Rahim Creator');
        $assignee = $this->user('Karim Assignee');
        $task     = $this->task($assignee, $creator);

        $this->actingAs($assignee)->postJson(route('tasks.comments.store', $task), [
            'comment' => 'Noting for myself, @Karim Assignee',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    /** The rule this whole feature exists for: the mentionable set is bounded to the task's own people. */
    public function test_a_real_user_who_is_not_party_to_the_task_cannot_be_mentioned(): void
    {
        Notification::fake();
        $creator   = $this->user('Rahim Creator');
        $assignee  = $this->user('Karim Assignee');
        $bystander = $this->user('Nasir Bystander');
        $task      = $this->task($assignee, $creator);

        $this->actingAs($assignee)->postJson(route('tasks.comments.store', $task), [
            'comment' => 'hey @Nasir Bystander take a look too',
        ])->assertOk();

        Notification::assertNothingSentTo($bystander);
    }

    public function test_a_comment_with_no_mention_notifies_no_one(): void
    {
        Notification::fake();
        $creator  = $this->user('Rahim Creator');
        $assignee = $this->user('Karim Assignee');
        $task     = $this->task($assignee, $creator);

        $this->actingAs($assignee)->postJson(route('tasks.comments.store', $task), [
            'comment' => 'Just a normal update, no one named here.',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    /** A task can now have several assignees at once — each one is still mentionable, and one mentioning another still excludes themselves. */
    public function test_a_shared_task_lets_any_assignee_mention_any_other_assignee(): void
    {
        $creator = $this->user('Rahim Creator');
        $a = $this->user('Anika Rahman');
        $b = $this->user('Bashir Uddin');
        $task = Task::create([
            'title' => 'Shared brochure', 'priority' => 'Medium', 'status' => 'Pending',
            'type' => 'Other', 'created_by' => $creator->id,
        ]);
        $task->assignees()->sync([$a->id, $b->id]);

        $this->actingAs($a)->postJson(route('tasks.comments.store', $task), [
            'comment' => '@Bashir Uddin can you take the next part, @Anika Rahman is done with hers',
        ])->assertOk();

        Notification::assertSentTo($b, TaskCommentMention::class);
        // Mentioning yourself alongside someone else still excludes only yourself.
        Notification::assertNotSentTo($a, TaskCommentMention::class);
    }

    public function test_the_comment_renders_the_mention_highlighted(): void
    {
        $creator  = $this->user('Rahim Creator');
        $assignee = $this->user('Karim Assignee');
        $task     = $this->task($assignee, $creator);

        $this->actingAs($assignee)->postJson(route('tasks.comments.store', $task), [
            'comment' => '@Rahim Creator, please review',
        ])->assertOk();

        $response = $this->actingAs($creator)->get(route('tasks.show', $task));

        $response->assertOk()->assertSee('class="mention"', false)->assertSee('@Rahim Creator');
    }
}
