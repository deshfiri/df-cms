<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use App\Notifications\RequestSubmitted;
use App\Notifications\TaskAssigned;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The dashboard bell is driven by Echo's `.notification()` callback on the
 * user's private channel, but only the flow-engine notifications ever carried
 * the 'broadcast' channel — everything else was database-only and appeared just
 * when the 60-second poll happened to run.
 *
 * Rule now: anything that writes a bell row also pushes it live.
 */
class RealtimeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    /** Editing a task otherwise goes through the change-approval queue. */
    private function privilegedUser(): User
    {
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);

        $user = $this->user();
        $user->assignRole('Manager');

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

    /** @return array<int,string> */
    private function channelsFor(BaseNotification $notification, User $notifiable): array
    {
        return $notification->via($notifiable);
    }

    public function test_every_database_notification_also_broadcasts(): void
    {
        $user = $this->user();

        $classes = collect(glob(app_path('Notifications/*.php')))
            ->map(fn ($file) => 'App\\Notifications\\' . basename($file, '.php'))
            ->filter(fn ($class) => class_exists($class) && !(new \ReflectionClass($class))->isAbstract());

        $this->assertGreaterThan(15, $classes->count(), 'Expected the notification classes to be discovered.');

        $missing = [];

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);

            // Skip anything that cannot be built without live domain models;
            // the channel list is what matters and it is declared statically.
            $source = file_get_contents($reflection->getFileName());

            $writesBellRow = str_contains($source, 'function toDatabase') || str_contains($source, 'function toArray');
            if (!$writesBellRow) {
                continue;
            }

            if (!str_contains($source, "'broadcast'")) {
                $missing[] = class_basename($class);
            }
        }

        $this->assertSame([], $missing, 'These notifications write a bell row but never push it live: ' . implode(', ', $missing));
    }

    public function test_a_bell_notification_declares_both_channels_for_a_user(): void
    {
        $user = $this->user();
        $request = new \App\Models\EmployeeRequest(['subject' => 'Leave', 'type' => 'Leave']);

        $channels = $this->channelsFor(new RequestSubmitted($request), $user);

        $this->assertContains('database', $channels);
        $this->assertContains('broadcast', $channels);
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    public function test_assigning_a_task_notifies_the_new_owner(): void
    {
        Notification::fake();

        $manager = $this->user();
        $worker  = $this->user();

        $this->actingAs($manager);

        app(TaskService::class)->create([
            'title'       => 'Draft the proposal',
            'assigned_to' => $worker->id,
            'priority'    => 'High',
            'status'      => 'Pending',
            'client_id'   => $this->client()->id,
        ]);

        Notification::assertSentTo(
            $worker,
            TaskAssigned::class,
            fn ($notification, $channels) => in_array('broadcast', $channels, true)
                && in_array('database', $channels, true)
        );
    }

    public function test_taking_a_task_yourself_is_not_news(): void
    {
        Notification::fake();

        $me = $this->user();
        $this->actingAs($me);

        app(TaskService::class)->create([
            'title'       => 'My own errand',
            'assigned_to' => $me->id,
            'priority'    => 'Low',
            'status'      => 'Pending',
            'client_id'   => $this->client()->id,
        ]);

        Notification::assertNothingSent();
    }

    public function test_an_unassigned_task_notifies_nobody(): void
    {
        Notification::fake();

        $this->actingAs($this->user());

        app(TaskService::class)->create([
            'title'     => 'Nobody owns this yet',
            'priority'  => 'Low',
            'status'    => 'Pending',
            'client_id' => $this->client()->id,
        ]);

        Notification::assertNothingSent();
    }

    public function test_reassigning_notifies_only_the_person_it_moved_to(): void
    {
        $manager = $this->privilegedUser();
        $first   = $this->user();
        $second  = $this->user();

        $this->actingAs($manager);

        $task = app(TaskService::class)->create([
            'title'       => 'Chase the invoice',
            'assigned_to' => $first->id,
            'priority'    => 'Medium',
            'status'      => 'Pending',
            'client_id'   => $this->client()->id,
        ]);

        Notification::fake();   // ignore the notification from creation

        app(TaskService::class)->update($task, ['assigned_to' => $second->id]);

        Notification::assertSentTo($second, TaskAssigned::class);
        Notification::assertNotSentTo($first, TaskAssigned::class);
    }

    public function test_editing_a_task_without_moving_it_does_not_re_notify(): void
    {
        $manager = $this->privilegedUser();
        $worker  = $this->user();

        $this->actingAs($manager);

        $task = app(TaskService::class)->create([
            'title'       => 'Ongoing work',
            'assigned_to' => $worker->id,
            'priority'    => 'Medium',
            'status'      => 'Pending',
            'client_id'   => $this->client()->id,
        ]);

        Notification::fake();

        app(TaskService::class)->update($task, ['priority' => 'Urgent']);

        Notification::assertNothingSent();
    }
}
