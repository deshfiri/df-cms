<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Services\Storage\Cloudinary\CloudinaryAdapter;
use App\Services\Storage\Cloudinary\CloudinaryClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Downloading a file attached to a task.
 *
 * Downloads used to ask the storage provider for the file's size and type just
 * before streaming. On Cloudinary a missing length became "Content-Length: 0"
 * (an empty file), and a compressed answer's length didn't match the bytes that
 * followed (a truncated one). The upload record's own size and type are used
 * now, and a provider that refused the upload no longer leaves a record behind.
 */
class TaskAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');

        foreach (['view tasks', 'manage tasks'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$permissions): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo($permissions)->fresh();
    }

    private function task(User $assignee, User $creator): Task
    {
        return Task::create([
            'title'       => 'Design the brochure',
            'priority'    => 'Medium',
            'status'      => 'Pending',
            'type'        => 'Other',
            'assigned_to' => $assignee->id,
            'created_by'  => $creator->id,
        ]);
    }

    /** @return array{0:Task,1:User,2:User} task, assignee, creator */
    private function setup_task(): array
    {
        $assignee = $this->user('view tasks');
        $creator  = $this->user('view tasks', 'manage tasks');

        return [$this->task($assignee, $creator), $assignee, $creator];
    }

    // ── The round trip ───────────────────────────────────────────────────

    public function test_an_uploaded_file_downloads_whole(): void
    {
        [$task, $assignee] = $this->setup_task();
        $body = str_repeat('%PDF-1.7 brochure bytes ', 400);

        $this->actingAs($assignee)
            ->post(route('tasks.attachments.store', $task), [
                'file' => UploadedFile::fake()->createWithContent('Brochure Final.pdf', $body),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $attachment = TaskAttachment::sole();

        $response = $this->actingAs($assignee)->get(route('tasks.attachments.download', [$task, $attachment]));

        $response->assertOk();
        $this->assertSame($body, $response->streamedContent());
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Brochure Final.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_name_with_slashes_or_non_latin_letters_still_downloads(): void
    {
        [$task, $assignee] = $this->setup_task();
        Storage::disk('local')->put('task-attachments/x/report.pdf', 'data');

        $attachment = TaskAttachment::create([
            'task_id' => $task->id, 'user_id' => $assignee->id,
            'original_name' => 'Q3\\রিপোর্ট/final.pdf', 'stored_name' => 'report.pdf',
            'file_path' => 'task-attachments/x/report.pdf', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'file_size' => 4,
        ]);

        $response = $this->actingAs($assignee)->get(route('tasks.attachments.download', [$task, $attachment]));

        $response->assertOk();
        $this->assertSame('data', $response->streamedContent());
        $this->assertStringContainsString('filename*=utf-8', $response->headers->get('Content-Disposition'));
    }

    // ── When it can't be served ──────────────────────────────────────────

    public function test_a_file_missing_from_storage_is_a_404_not_a_broken_download(): void
    {
        [$task, $assignee] = $this->setup_task();

        $attachment = TaskAttachment::create([
            'task_id' => $task->id, 'user_id' => $assignee->id,
            'original_name' => 'gone.pdf', 'stored_name' => 'gone.pdf',
            'file_path' => 'task-attachments/x/gone.pdf', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'file_size' => 10,
        ]);

        $this->actingAs($assignee)->get(route('tasks.attachments.download', [$task, $attachment]))->assertNotFound();
    }

    public function test_an_attachment_is_only_reachable_through_its_own_task(): void
    {
        [$task, $assignee, $creator] = $this->setup_task();
        $other = $this->task($assignee, $creator);
        Storage::disk('local')->put('task-attachments/x/a.txt', 'hello');

        $attachment = TaskAttachment::create([
            'task_id' => $other->id, 'user_id' => $assignee->id,
            'original_name' => 'a.txt', 'stored_name' => 'a.txt',
            'file_path' => 'task-attachments/x/a.txt', 'disk' => 'local', 'mime_type' => 'text/plain', 'file_size' => 5,
        ]);

        $this->actingAs($assignee)->get(route('tasks.attachments.download', [$task, $attachment]))->assertNotFound();
    }

    public function test_someone_who_cannot_see_the_task_cannot_download_from_it(): void
    {
        [$task, $assignee] = $this->setup_task();
        Storage::disk('local')->put('task-attachments/x/a.txt', 'secret');

        $attachment = TaskAttachment::create([
            'task_id' => $task->id, 'user_id' => $assignee->id,
            'original_name' => 'a.txt', 'stored_name' => 'a.txt',
            'file_path' => 'task-attachments/x/a.txt', 'disk' => 'local', 'mime_type' => 'text/plain', 'file_size' => 6,
        ]);

        $this->actingAs($this->user('view tasks'))
            ->get(route('tasks.attachments.download', [$task, $attachment]))
            ->assertForbidden();
    }

    /** The provider refusing the write must be an error the uploader sees, not a dead record. */
    public function test_a_refused_upload_says_so_and_saves_nothing(): void
    {
        [$task, $assignee] = $this->setup_task();

        $refusing = Mockery::mock(FilesystemAdapter::class);
        $refusing->shouldReceive('putFileAs')->andReturnFalse();
        Storage::set('local', $refusing);

        $this->actingAs($assignee)
            ->postJson(route('tasks.attachments.store', $task), ['file' => UploadedFile::fake()->create('plan.docx', 12)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, TaskAttachment::count());
    }

    // ── Cloudinary: what the CDN reports ─────────────────────────────────

    private function cloudinary(): CloudinaryClient
    {
        return new CloudinaryClient('demo-cloud', 'key', 'secret');
    }

    public function test_a_cdn_answer_without_a_length_is_unknown_not_zero(): void
    {
        Http::fake(['res.cloudinary.com/*' => Http::response('', 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertSame('', $this->cloudinary()->head('task-attachments/1/a.pdf')['size']);
    }

    public function test_a_compressed_cdn_length_is_not_trusted(): void
    {
        Http::fake(['res.cloudinary.com/*' => Http::response('', 200, ['Content-Encoding' => 'gzip', 'Content-Length' => '120'])]);

        $this->assertSame('', $this->cloudinary()->head('task-attachments/1/a.pdf')['size']);
    }

    public function test_an_unknown_length_falls_back_to_the_stored_byte_count(): void
    {
        Http::fake([
            'res.cloudinary.com/*' => Http::response('', 200, ['Content-Type' => 'application/pdf']),
            'api.cloudinary.com/*' => Http::response(['public_id' => 'task-attachments/1/a.pdf', 'bytes' => 48213]),
        ]);

        $adapter = new CloudinaryAdapter($this->cloudinary());

        $this->assertSame(48213, $adapter->fileSize('task-attachments/1/a.pdf')->fileSize());
    }

    /**
     * The reported bug: images downloaded, ZIPs and PDFs did not.
     *
     * Cloudinary blocks public delivery of PDF and ZIP files by default and
     * answers 401. The file is there; reads now go through the signed download
     * API instead of failing as "no longer available".
     */
    public function test_a_file_the_cdn_refuses_to_deliver_is_read_through_the_signed_api(): void
    {
        Http::fake([
            'res.cloudinary.com/*' => Http::response('', 401, ['x-cld-error' => 'deny or ACL failure']),
            'api.cloudinary.com/v1_1/demo-cloud/raw/download*' => Http::response('PK-zip-bytes', 200),
        ]);

        $stream = $this->cloudinary()->readStream('task-attachments/1/archive.zip');

        $this->assertSame('PK-zip-bytes', stream_get_contents($stream));
        Http::assertSent(function (HttpRequest $request) {
            if (!str_contains($request->url(), '/raw/download')) {
                return false;
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['public_id'] === 'task-attachments/1/archive.zip'
                && $query['type'] === 'upload'
                && !empty($query['signature'])
                && $query['api_key'] === 'key';
        });
    }

    public function test_the_signed_link_is_signed_with_the_secret_not_exposed_in_it(): void
    {
        $url = $this->cloudinary()->signedDownloadUrl('a/b.pdf');

        $this->assertStringContainsString('https://api.cloudinary.com/v1_1/demo-cloud/raw/download?', $url);
        $this->assertStringNotContainsString('secret', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $expected = sha1('public_id=a/b.pdf&timestamp=' . $query['timestamp'] . '&type=upload' . 'secret');
        $this->assertSame($expected, $query['signature']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function fileTypes(): array
    {
        return [
            'jpg'  => ['photo.jpg', 'image/jpeg'],
            'png'  => ['image.png', 'image/png'],
            'webp' => ['image.webp', 'image/webp'],
            'pdf'  => ['brochure.pdf', 'application/pdf'],
            'zip'  => ['archive.zip', 'application/zip'],
            'docx' => ['letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx' => ['sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'csv'  => ['list.csv', 'text/csv'],
            'txt'  => ['notes.txt', 'text/plain'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fileTypes')]
    public function test_every_file_type_downloads_whole_as_a_download(string $name, string $mime): void
    {
        [$task, $assignee] = $this->setup_task();
        $body = random_bytes(3000);
        Storage::disk('local')->put('task-attachments/x/' . $name, $body);

        $attachment = TaskAttachment::create([
            'task_id' => $task->id, 'user_id' => $assignee->id, 'original_name' => $name, 'stored_name' => $name,
            'file_path' => 'task-attachments/x/' . $name, 'disk' => 'local', 'mime_type' => $mime, 'file_size' => 3000,
        ]);

        $response = $this->actingAs($assignee)->get(route('tasks.attachments.download', [$task, $attachment]));

        $response->assertOk();
        $this->assertSame($body, $response->streamedContent());
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($name, $response->headers->get('Content-Disposition'));
        // Text types gain "; charset=UTF-8" on the way out, which is correct.
        $this->assertStringStartsWith($mime, $response->headers->get('Content-Type'));
    }

    public function test_reads_ask_the_cdn_for_the_files_own_bytes(): void
    {
        Http::fake(['res.cloudinary.com/*' => Http::response('%PDF-raw-bytes', 200)]);

        $stream = $this->cloudinary()->readStream('task-attachments/1/a.pdf');

        $this->assertSame('%PDF-raw-bytes', stream_get_contents($stream));
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
            && $request->hasHeader('Accept-Encoding', 'identity'));
    }
}
