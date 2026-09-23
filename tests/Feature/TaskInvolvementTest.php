<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskInvolvement;
use App\Models\User;
use App\Services\TaskInvolvementService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Who actually worked on a task — the basis for performance credit.
 *
 * The rules under test are the ones documented on TaskInvolvementService:
 * passing through someone's hands earns nothing, doing the work earns a share
 * in proportion to what was done, and reviewing or directing is recorded but
 * never takes a share of the doers' credit.
 */
class TaskInvolvementTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->tasks = app(TaskService::class);
    }

    private function user(string $name, string ...$permissions): User
    {
        return tap(User::factory()->create(['is_active' => true, 'name' => $name]))
            ->givePermissionTo($permissions ?: ['view tasks'])->fresh();
    }

    private function create(User $manager, ?User $assignee): Task
    {
        $this->actingAs($manager);

        return $this->tasks->create([
            'title' => 'Brochure', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => $assignee ? [$assignee->id] : [],
        ]);
    }

    private function as(User $user): self
    {
        $this->actingAs($user);

        return $this;
    }

    private function involvementOf(Task $task, User $user): ?TaskInvolvement
    {
        return TaskInvolvement::where('task_id', $task->id)->where('user_id', $user->id)->first();
    }

    // ── The ordinary case ────────────────────────────────────────────────

    public function test_a_sole_assignee_who_does_the_work_gets_all_of_it(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);

        $this->as($anika)->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->as($anika)->tasks->submitForReview($task->fresh(), $anika);
        $this->as($manager)->tasks->review($task->fresh(), $manager, true);

        $mine = $this->involvementOf($task, $anika);
        $this->assertSame('primary', $mine->role);
        $this->assertSame(6.0, $mine->points);          // started 2 + submitted 4
        $this->assertSame(1.0, $mine->share);
        $this->assertSame(['started' => 2, 'submitted' => 4], $mine->breakdown);

        $boss = $this->involvementOf($task, $manager);
        $this->assertSame('reviewer', $boss->role);
        $this->assertSame(0.0, $boss->share, 'Reviewing is recorded, never a share of the work.');
        $this->assertSame(2.0, $boss->review_points);
    }

    // ── The case this exists for ─────────────────────────────────────────

    public function test_passing_through_someones_hands_earns_them_nothing(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $task    = $this->create($manager, $anika);

        // Reassigned before Anika did anything.
        $this->as($manager)->tasks->update($task, ['assignee_ids' => [$bashir->id]]);
        $this->as($bashir)->tasks->changeWorkingStatus($task->fresh(), $bashir, 'In Progress');

        $this->assertSame('passed_through', $this->involvementOf($task, $anika)->role);
        $this->assertSame(0.0, $this->involvementOf($task, $anika)->share);
        $this->assertNotNull($this->involvementOf($task, $anika)->released_at);
        $this->assertSame(1.0, $this->involvementOf($task, $bashir)->share);
    }

    public function test_work_done_before_a_handover_keeps_its_share(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $task    = $this->create($manager, $anika);

        // Anika starts and uploads a draft (2 + 2 = 4)...
        $this->as($anika)->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->as($anika)->tasks->uploadAttachment($task->fresh(), UploadedFile::fake()->create('draft.pdf', 10));

        // ...then it is handed to Bashir, who submits it (4, plus 1 for holding it).
        $this->as($manager)->tasks->update($task->fresh(), ['assignee_ids' => [$bashir->id]]);
        $this->as($bashir)->tasks->submitForReview($task->fresh(), $bashir);

        $anikaRow  = $this->involvementOf($task, $anika);
        $bashirRow = $this->involvementOf($task, $bashir);

        $this->assertSame('contributor', $anikaRow->role);
        $this->assertSame('primary', $bashirRow->role);
        $this->assertEqualsWithDelta(4 / 9, $anikaRow->share, 0.0001);
        $this->assertEqualsWithDelta(5 / 9, $bashirRow->share, 0.0001);
        $this->assertEqualsWithDelta(1.0, $anikaRow->share + $bashirRow->share, 0.0001);
    }

    // ── Who counts as doing the work ─────────────────────────────────────

    public function test_a_manager_commenting_on_work_they_assigned_is_not_doing_it(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);

        $this->as($manager)->tasks->addComment($task, 'Please use the new logo');

        $boss = $this->involvementOf($task, $manager);
        $this->assertSame('creator', $boss->role);
        $this->assertSame(0.0, $boss->points);
        $this->assertSame(1.0, $this->involvementOf($task, $anika)->share);
    }

    public function test_a_helper_who_contributes_a_file_earns_a_share(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $helper  = $this->user('Helper');
        $task    = $this->create($manager, $anika);

        $this->as($helper)->tasks->uploadAttachment($task, UploadedFile::fake()->create('photos.zip', 10));

        $row = $this->involvementOf($task, $helper);
        $this->assertSame('contributor', $row->role);
        $this->assertEqualsWithDelta(2 / 3, $row->share, 0.0001);    // 2 of (2 + Anika's 1 for holding)
    }

    public function test_volume_is_not_effort(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);

        foreach (range(1, 10) as $i) {
            $this->as($anika)->tasks->addComment($task, "Update {$i}");
        }

        $this->assertSame(1.5, $this->involvementOf($task, $anika)->points, 'Comments are capped at 1.5 points.');
    }

    public function test_only_the_first_start_counts_as_starting(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);

        foreach (['In Progress', 'On Hold', 'In Progress', 'On Hold', 'In Progress', 'On Hold', 'In Progress'] as $status) {
            $this->as($anika)->tasks->changeWorkingStatus($task->fresh(), $anika, $status);
        }

        $this->assertSame(['started' => 2, 'resumed' => 1], $this->involvementOf($task, $anika)->breakdown);
    }

    // ── What never counts ────────────────────────────────────────────────

    public function test_opening_a_task_is_not_involvement(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);
        $before  = TaskActivity::count();

        $this->actingAs($anika)->getJson(route('tasks.show', $task))->assertOk();
        $this->actingAs($manager)->get(route('tasks.show', $task));

        $this->assertSame($before, TaskActivity::count());
        $this->assertSame(0, $this->involvementOf($task, $anika)->events_count);
    }

    // ── Auditability ─────────────────────────────────────────────────────

    public function test_involvement_is_a_pure_function_of_the_history(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $task    = $this->create($manager, $anika);
        $this->as($anika)->tasks->changeWorkingStatus($task, $anika, 'In Progress');
        $this->as($anika)->tasks->addComment($task->fresh(), 'Halfway');

        $snapshot = fn () => TaskInvolvement::where('task_id', $task->id)->orderBy('user_id')
            ->get(['user_id', 'role', 'points', 'share', 'breakdown'])->toArray();

        $first = $snapshot();
        app(TaskInvolvementService::class)->rebuild($task->fresh());
        app(TaskInvolvementService::class)->rebuild($task->fresh());

        $this->assertSame($first, $snapshot());
    }

    public function test_history_from_before_events_existed_counts_by_the_same_rules(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');

        $task = Task::create([
            'title' => 'Old', 'priority' => 'Medium', 'status' => 'In Progress', 'type' => 'Other',
            'created_by' => $manager->id,
        ]);
        $task->assignees()->sync([$bashir->id]);

        // Free-text history only, the way it was logged before `event` existed.
        $at = now()->subDays(5);
        foreach ([
            [$manager, 'Created', 'Task "Old" created', null, null],
            [$anika, 'Status Changed', 'Pending → In Progress', null, null],
            [$anika, 'Attachment Added', 'draft.pdf', null, null],
            [$manager, 'Updated', 'Task updated', json_encode(['assigned_to' => $anika->id]), json_encode(['assigned_to' => $bashir->id])],
            [$bashir, 'Submitted', 'Submitted for review', null, null],
        ] as $i => [$who, $action, $description, $old, $new]) {
            TaskActivity::create([
                'task_id' => $task->id, 'user_id' => $who->id, 'action' => $action,
                'description' => $description, 'old_value' => $old, 'new_value' => $new,
                'created_at' => $at->copy()->addMinutes($i), 'updated_at' => $at->copy()->addMinutes($i),
            ]);
        }

        app(TaskInvolvementService::class)->rebuild($task);

        $this->assertSame('contributor', $this->involvementOf($task, $anika)->role);
        $this->assertSame('primary', $this->involvementOf($task, $bashir)->role);
        $this->assertEqualsWithDelta(4 / 9, $this->involvementOf($task, $anika)->share, 0.0001);
    }

    public function test_structured_events_are_logged_for_what_happened(): void
    {
        $manager = $this->user('Manager', 'view tasks', 'manage tasks');
        $anika   = $this->user('Anika');
        $bashir  = $this->user('Bashir');
        $task    = $this->create($manager, $anika);

        $this->as($manager)->tasks->update($task, ['assignee_ids' => [$bashir->id], 'due_date' => '2026-12-01']);

        $events = TaskActivity::where('task_id', $task->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['created', 'updated', 'reassigned', 'due_changed'], $events);

        $reassigned = TaskActivity::where('task_id', $task->id)->where('event', 'reassigned')->sole();
        $this->assertSame(['added' => [$bashir->id], 'removed' => [$anika->id]], $reassigned->meta);
        $this->assertSame('Anika → Bashir', $reassigned->description);
    }
}
