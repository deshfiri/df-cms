<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowItemAttachment;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Services\FlowService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Showing an image attachment in the page: thumbnails and the lightbox.
 *
 * Previews go through the same authorization as downloads, and only raster
 * images are ever served inline — anything else, SVG above all, could run as a
 * page on this site's origin.
 */
class AttachmentPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        foreach (['view tasks', 'manage tasks', 'manage workflows'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$permissions): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo($permissions ?: ['view tasks'])->fresh();
    }

    private function taskWithFile(User $manager, User $assignee, UploadedFile $file): array
    {
        $this->actingAs($manager);
        $service = app(TaskService::class);
        $task = $service->create(['title' => 'Logo', 'priority' => 'Medium', 'status' => 'Pending', 'type' => 'Other', 'assigned_to' => $assignee->id]);

        return [$task, $service->uploadAttachment($task, $file)];
    }

    public function test_a_task_image_is_shown_inline_and_locked_down(): void
    {
        $manager = $this->user('view tasks', 'manage tasks');
        $anika   = $this->user();
        [$task, $image] = $this->taskWithFile($manager, $anika, UploadedFile::fake()->image('logo.png', 40, 40));

        $response = $this->actingAs($anika)->get(route('tasks.attachments.preview', [$task, $image]))->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(Storage::disk('local')->get($image->file_path), $response->streamedContent());
        $this->assertTrue($image->isPreviewableImage());
    }

    public function test_jpg_and_webp_preview_too(): void
    {
        $manager = $this->user('view tasks', 'manage tasks');
        $anika   = $this->user();

        foreach (['photo.jpg' => 'image/jpeg', 'art.webp' => 'image/webp'] as $name => $mime) {
            [$task, $image] = $this->taskWithFile($manager, $anika, UploadedFile::fake()->create($name, 5, $mime));
            $image->forceFill(['mime_type' => $mime])->save();

            $this->actingAs($anika)->get(route('tasks.attachments.preview', [$task, $image]))
                ->assertOk()
                ->assertHeader('Content-Type', $mime);
        }
    }

    public function test_someone_who_cannot_see_the_task_cannot_preview_its_files(): void
    {
        $manager   = $this->user('view tasks', 'manage tasks');
        $anika     = $this->user();
        $bystander = $this->user();
        [$task, $image] = $this->taskWithFile($manager, $anika, UploadedFile::fake()->image('logo.png'));

        $this->actingAs($bystander)->get(route('tasks.attachments.preview', [$task, $image]))->assertForbidden();
    }

    public function test_non_images_and_svg_are_never_served_inline(): void
    {
        $manager = $this->user('view tasks', 'manage tasks');

        [$task, $pdf] = $this->taskWithFile($manager, $manager, UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'));
        $this->actingAs($manager)->get(route('tasks.attachments.preview', [$task, $pdf]))->assertStatus(415);

        [$svgTask, $svg] = $this->taskWithFile($manager, $manager, UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        $svg->forceFill(['mime_type' => 'image/svg+xml'])->save();
        $this->assertFalse($svg->isPreviewableImage());
        $this->actingAs($manager)->get(route('tasks.attachments.preview', [$svgTask, $svg]))->assertStatus(415);

        // The download is unaffected — only inline display is refused.
        $this->actingAs($manager)->get(route('tasks.attachments.download', [$task, $pdf]))->assertOk();
    }

    public function test_a_file_from_another_task_is_not_found(): void
    {
        $manager = $this->user('view tasks', 'manage tasks');
        [$task] = $this->taskWithFile($manager, $manager, UploadedFile::fake()->image('a.png'));
        [, $other] = $this->taskWithFile($manager, $manager, UploadedFile::fake()->image('b.png'));

        $this->actingAs($manager)->get(route('tasks.attachments.preview', [$task, $other]))->assertNotFound();
    }

    public function test_a_workflow_image_previews_for_whoever_can_view_the_item(): void
    {
        $worker = $this->user();
        $admin  = $this->user('manage workflows');
        $flow   = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Brief', 'position' => 1])->users()->sync([$worker->id]);
        $flows = app(FlowService::class);
        $item  = $flows->claim($flows->createItem($flow->refresh(), ['title' => 'Logo'], $admin)->fresh(), $worker);

        $this->actingAs($worker)->postJson(route('flow-items.attachments.store', $item), [
            'kind' => 'file', 'file' => UploadedFile::fake()->image('mock.png', 30, 30),
        ])->assertOk();
        $image = FlowItemAttachment::sole();

        $this->actingAs($worker)->get(route('flow-items.attachments.preview', [$item, $image]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($this->user())->get(route('flow-items.attachments.preview', [$item, $image]))->assertForbidden();

        // The item page offers the thumbnail and the lightbox trigger.
        $this->actingAs($worker)->get(route('flow-items.show', $item))
            ->assertOk()
            ->assertSee(route('flow-items.attachments.preview', [$item, $image]), false)
            ->assertSee('data-preview-src', false);
    }
}
