<?php

namespace Tests\Feature;

use App\Jobs\PushUploadToProvider;
use App\Models\Flow;
use App\Models\FlowItemAttachment;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Services\FlowService;
use App\Services\Storage\StorageSettings;
use App\Services\TaskService;
use App\Support\UploadLimit;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Many files at once: each lands on this server fast, and a queued job moves it
 * to the storage provider afterwards (UploadStaging, PushUploadToProvider).
 */
class BulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $anika;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('cloudinary');
        foreach (['view tasks', 'manage tasks', 'manage workflows'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true]))->givePermissionTo(['view tasks', 'manage tasks'])->fresh();
        $this->anika   = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view tasks')->fresh();
    }

    private function activateCloudinary(): void
    {
        Setting::set(StorageSettings::KEY_CLOUDINARY_CLOUD, 'demo-cloud');
        Setting::set(StorageSettings::KEY_CLOUDINARY_KEY, '123');
        Setting::set(StorageSettings::KEY_CLOUDINARY_SECRET, Crypt::encryptString('shh'));
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDINARY);
    }

    /** A real queue, so uploads are parked locally and moved by a job. */
    private function withQueue(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
    }

    private function task(): Task
    {
        $this->actingAs($this->manager);
        $task = app(TaskService::class)->create([
            'title' => 'Poster', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other', 'assignee_ids' => [$this->anika->id],
        ]);
        $this->actingAs($this->anika);

        return app(TaskService::class)->changeWorkingStatus($task, $this->anika, 'In Progress');
    }

    public function test_with_a_provider_and_a_queue_each_file_is_parked_locally_and_moved_later(): void
    {
        $this->activateCloudinary();
        $this->withQueue();
        $task = $this->task();

        foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $name) {
            $this->actingAs($this->anika)
                ->post(route('tasks.attachments.store', $task), ['file' => UploadedFile::fake()->create($name, 30)], ['Accept' => 'application/json'])
                ->assertOk();
        }

        $attachments = TaskAttachment::all();
        $this->assertCount(3, $attachments);
        foreach ($attachments as $a) {
            // Readable at once, from where it really is.
            $this->assertSame('local', $a->disk);
            Storage::disk('local')->assertExists($a->file_path);
            Storage::disk('cloudinary')->assertMissing($a->file_path);
        }

        Queue::assertPushed(PushUploadToProvider::class, 3);
        Queue::assertPushed(PushUploadToProvider::class, fn ($job) => $job->disk === 'cloudinary');
    }

    public function test_the_job_moves_the_file_and_repoints_the_record(): void
    {
        $this->activateCloudinary();
        $this->withQueue();
        $task = $this->task();
        $attachment = app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->createWithContent('brief.txt', 'the brief'));

        (new PushUploadToProvider($attachment, 'cloudinary'))->handle();

        $attachment->refresh();
        $this->assertSame('cloudinary', $attachment->disk);
        $this->assertSame('the brief', Storage::disk('cloudinary')->get($attachment->file_path));
        Storage::disk('local')->assertMissing($attachment->file_path);

        // Downloads follow the record to its new home.
        $this->actingAs($this->anika)
            ->get(route('tasks.attachments.download', [$task, $attachment]))
            ->assertOk();

        // A second run (a retry after the move) changes nothing.
        (new PushUploadToProvider($attachment, 'cloudinary'))->handle();
        $this->assertSame('cloudinary', $attachment->fresh()->disk);
    }

    public function test_a_file_removed_while_it_was_being_copied_does_not_come_back(): void
    {
        $this->activateCloudinary();
        $this->withQueue();
        $task = $this->task();
        $attachment = app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->createWithContent('brief.txt', 'x'));
        $job = new PushUploadToProvider($attachment, 'cloudinary');

        // The row goes while the job holds it.
        DB::table('task_attachments')->where('id', $attachment->id)->delete();
        $job->handle();

        Storage::disk('cloudinary')->assertMissing($attachment->file_path);
    }

    public function test_a_refused_copy_leaves_the_file_working_where_it_is(): void
    {
        $this->activateCloudinary();
        $this->withQueue();
        $task = $this->task();
        $attachment = app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->createWithContent('brief.txt', 'x'));

        $refusing = Mockery::mock(Filesystem::class);
        $refusing->shouldReceive('put')->andReturnFalse();
        Storage::set('cloudinary', $refusing);

        try {
            (new PushUploadToProvider($attachment, 'cloudinary'))->handle();
            $this->fail('Expected the job to fail so the queue retries it.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stays on the local disk', $e->getMessage());
        }

        $this->assertSame('local', $attachment->fresh()->disk);
        Storage::disk('local')->assertExists($attachment->file_path);
    }

    public function test_without_a_queue_files_go_straight_to_the_provider_as_before(): void
    {
        $this->activateCloudinary();
        Queue::fake(); // the sync connection stays the default
        $task = $this->task();

        $attachment = app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->create('a.pdf', 10));

        $this->assertSame('cloudinary', $attachment->disk);
        Queue::assertNothingPushed();
    }

    public function test_self_hosted_needs_no_second_hop(): void
    {
        $this->withQueue();
        $task = $this->task();

        $attachment = app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->create('a.pdf', 10));

        $this->assertSame('local', $attachment->disk);
        Queue::assertNothingPushed();
    }

    public function test_workflow_files_are_parked_and_moved_the_same_way(): void
    {
        $this->activateCloudinary();
        $this->withQueue();
        $admin  = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage workflows');
        $worker = User::factory()->create(['is_active' => true]);
        $flow   = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Brief', 'position' => 1])->users()->sync([$worker->id]);
        $item = app(FlowService::class)->createItem($flow->refresh(), ['title' => 'Logo'], $admin);
        app(FlowService::class)->claim($item->fresh(), $worker);

        foreach (['one.png', 'two.png'] as $name) {
            $this->actingAs($worker)
                ->postJson(route('flow-items.attachments.store', $item), ['kind' => 'file', 'file' => UploadedFile::fake()->create($name, 20)])
                ->assertOk();
        }

        $this->assertSame(['local', 'local'], FlowItemAttachment::pluck('disk')->all());
        Queue::assertPushed(PushUploadToProvider::class, 2);

        $attachment = FlowItemAttachment::first();
        (new PushUploadToProvider($attachment, 'cloudinary'))->handle();
        $this->assertSame('cloudinary', $attachment->fresh()->disk);

        // The item page offers the same bulk queue, with the real limit.
        $this->actingAs($worker)->get(route('flow-items.show', $item))
            ->assertOk()
            ->assertSee('id="attFile" class="form-control form-control-sm" multiple', false)
            ->assertSee("makeUploadQueue('#attFile'", false)
            ->assertSee('up to ' . UploadLimit::label(UploadLimit::bytes(51200)) . ' each', false)
            ->assertSee('id="attCount"', false);
    }

    public function test_files_uploaded_ahead_are_recorded_on_the_submission(): void
    {
        $task  = $this->task();
        $other = $this->task();
        $mine  = app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->create('final.pdf', 10));
        $also  = app(TaskService::class)->uploadAttachment($task, UploadedFile::fake()->create('source.zip', 10));
        $elsewhere = app(TaskService::class)->uploadAttachment($other, UploadedFile::fake()->create('x.pdf', 10));

        $this->actingAs($this->anika)
            ->postJson(route('tasks.submit', $task), [
                'note' => 'All done',
                'attachment_ids' => [$mine->id, $also->id, $elsewhere->id, 999999],
            ])
            ->assertOk();

        $meta = TaskActivity::where('task_id', $task->id)->where('event', 'submitted')->sole()->meta;
        // Only this person's files on this task.
        $this->assertEqualsCanonicalizing([$mine->id, $also->id], $meta['attachment_ids']);
    }

    public function test_files_uploaded_ahead_satisfy_a_required_file(): void
    {
        $this->actingAs($this->manager);
        $task = app(TaskService::class)->create([
            'title' => 'Poster', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other',
            'assignee_ids' => [$this->anika->id], 'requires_attachment' => true,
        ]);
        $this->actingAs($this->anika);
        app(TaskService::class)->changeWorkingStatus($task, $this->anika, 'In Progress');
        $file = app(TaskService::class)->uploadAttachment($task->fresh(), UploadedFile::fake()->create('final.pdf', 10));

        $this->actingAs($this->anika)
            ->postJson(route('tasks.submit', $task), ['attachment_ids' => [$file->id]])
            ->assertOk();

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_the_upload_limit_is_the_smaller_of_the_apps_and_phps(): void
    {
        $php = UploadedFile::getMaxFilesize();

        $this->assertSame((int) min(20480 * 1024, $php), UploadLimit::bytes(20480));
        $this->assertSame(1024, UploadLimit::bytes(1)); // a tiny app limit always wins
        $this->assertSame('20 MB', UploadLimit::label(20971520));
        $this->assertSame('1.5 MB', UploadLimit::label(1572864));
        $this->assertSame('2 MB', UploadLimit::label(2097152));
        $this->assertSame('512 KB', UploadLimit::label(524288));
    }

    public function test_the_pages_offer_bulk_upload_with_the_real_limit(): void
    {
        $task = $this->task();
        $label = UploadLimit::label(UploadLimit::bytes(20480));

        $this->actingAs($this->anika)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('id="taskFileInput" class="form-control form-control-sm" multiple', false)
            ->assertSee('makeUploadQueue', false)
            ->assertSee('up to ' . $label . ' each', false)
            // The submit dialog uploads through the queue too.
            ->assertSee('TASK_UPLOAD_MAX = ' . UploadLimit::bytes(20480), false);

        $this->actingAs($this->anika)->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('window.makeUploadQueue', false);
    }

    public function test_storage_status_says_when_uploads_are_waiting_for_a_worker(): void
    {
        $this->activateCloudinary();
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\PushUploadToProvider']),
            'attempts' => 0, 'available_at' => time(), 'created_at' => time(),
        ]);

        $this->artisan('storage:status')
            ->expectsOutputToContain('1 upload(s) are on this server waiting to be copied to cloudinary')
            ->assertSuccessful();
    }
}
