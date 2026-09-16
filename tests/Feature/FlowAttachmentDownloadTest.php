<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\FlowItemAttachment;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Downloading a file attached to a workflow item.
 *
 * Same defect the task attachments had: the download asked the storage provider
 * for the file's size and type just before streaming, which on a CDN could
 * answer with no length (an empty file) or a compressed one (a truncated file);
 * and a provider that refused the upload still left a row behind that listed
 * fine and could never be downloaded.
 */
class FlowAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->flow = app(FlowService::class);
        Permission::firstOrCreate(['name' => 'manage workflows', 'guard_name' => 'web']);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    /** An item at a stage the returned user works on, so they may view and attach. */
    private function itemFor(User $worker): FlowItem
    {
        $admin = tap($this->user())->givePermissionTo('manage workflows');
        $flow  = Flow::create(['name' => 'Delivery', 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Brief', 'position' => 1])->users()->sync([$worker->id]);
        $flow->stages()->create(['name' => 'Review', 'position' => 2])->users()->sync([$admin->id]);

        $item = $this->flow->createItem($flow->refresh(), ['title' => 'Logo'], $admin);

        return $this->flow->claim($item->fresh(), $worker);
    }

    public function test_an_attached_file_downloads_whole(): void
    {
        $worker = $this->user();
        $item   = $this->itemFor($worker);
        $body   = str_repeat('brief bytes ', 500);

        $this->actingAs($worker)
            ->postJson(route('flow-items.attachments.store', $item), [
                'kind' => 'file',
                'file' => UploadedFile::fake()->createWithContent('Brief Final.pdf', $body),
            ])
            ->assertOk();

        $attachment = FlowItemAttachment::sole();

        $response = $this->actingAs($worker)
            ->get(route('flow-items.attachments.download', [$item, $attachment]))
            ->assertOk();

        $this->assertSame($body, $response->streamedContent());
        $this->assertStringContainsString('Brief Final.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_file_missing_from_storage_is_a_404_not_a_broken_download(): void
    {
        $worker = $this->user();
        $item   = $this->itemFor($worker);

        $attachment = FlowItemAttachment::create([
            'flow_item_id' => $item->id, 'kind' => 'file', 'uploaded_by' => $worker->id,
            'original_name' => 'gone.pdf', 'file_path' => 'flow-attachments/x/gone.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'file_size' => 10,
        ]);

        $this->actingAs($worker)
            ->get(route('flow-items.attachments.download', [$item, $attachment]))
            ->assertNotFound();
    }

    public function test_a_refused_upload_says_so_and_saves_nothing(): void
    {
        $worker = $this->user();
        $item   = $this->itemFor($worker);

        $refusing = Mockery::mock(FilesystemAdapter::class);
        $refusing->shouldReceive('putFileAs')->andReturnFalse();
        Storage::set('local', $refusing);

        $this->actingAs($worker)
            ->postJson(route('flow-items.attachments.store', $item), [
                'kind' => 'file',
                'file' => UploadedFile::fake()->create('plan.docx', 12),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, FlowItemAttachment::count());
    }

    public function test_a_link_or_note_is_not_downloadable(): void
    {
        $worker = $this->user();
        $item   = $this->itemFor($worker);

        $note = FlowItemAttachment::create([
            'flow_item_id' => $item->id, 'kind' => 'note', 'body' => 'Remember the margins', 'uploaded_by' => $worker->id,
        ]);

        $this->actingAs($worker)
            ->get(route('flow-items.attachments.download', [$item, $note]))
            ->assertNotFound();
    }

    public function test_an_attachment_is_only_reachable_through_its_own_item(): void
    {
        $worker = $this->user();
        $item   = $this->itemFor($worker);
        $other  = $this->itemFor($worker);
        Storage::disk('local')->put('flow-attachments/x/a.txt', 'hello');

        $attachment = FlowItemAttachment::create([
            'flow_item_id' => $other->id, 'kind' => 'file', 'uploaded_by' => $worker->id,
            'original_name' => 'a.txt', 'file_path' => 'flow-attachments/x/a.txt',
            'disk' => 'local', 'mime_type' => 'text/plain', 'file_size' => 5,
        ]);

        $this->actingAs($worker)
            ->get(route('flow-items.attachments.download', [$item, $attachment]))
            ->assertNotFound();
    }

    public function test_someone_outside_the_workflow_cannot_download_from_it(): void
    {
        $worker = $this->user();
        $item   = $this->itemFor($worker);
        Storage::disk('local')->put('flow-attachments/x/a.txt', 'secret');

        $attachment = FlowItemAttachment::create([
            'flow_item_id' => $item->id, 'kind' => 'file', 'uploaded_by' => $worker->id,
            'original_name' => 'a.txt', 'file_path' => 'flow-attachments/x/a.txt',
            'disk' => 'local', 'mime_type' => 'text/plain', 'file_size' => 6,
        ]);

        $this->actingAs($this->user())
            ->get(route('flow-items.attachments.download', [$item, $attachment]))
            ->assertForbidden();
    }
}
