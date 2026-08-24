<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Moving already-stored files onto a new provider.
 *
 * The ordering rule under test: copy first, repoint second, never delete. A
 * failure part-way therefore leaves every record still reading correctly from
 * the disk it was already on.
 */
class StorageMigrateCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(string $disk, string $path, string $body = 'contents'): ClientDocument
    {
        Storage::disk($disk)->put($path, $body);

        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'Migrate Client',
            'brand_name'  => 'Brand',
            'category_id' => $category->id,
        ]);
        $type = DocumentType::create(['name' => 'Type ' . uniqid(), 'is_active' => true]);

        return ClientDocument::create([
            'client_id'        => $client->id,
            'document_type_id' => $type->id,
            'uploaded_by'      => User::factory()->create()->id,
            'title'            => 'Doc',
            'original_name'    => basename($path),
            'stored_name'      => basename($path),
            'disk'             => $disk,
            'path'             => $path,
            'extension'        => 'pdf',
            'mime_type'        => 'application/pdf',
            'file_size'        => strlen($body),
            'version'          => 1,
        ]);
    }

    public function test_a_record_backed_file_is_copied_and_repointed(): void
    {
        Storage::fake('local');
        Storage::fake('cloudflare');

        $document = $this->makeDocument('local', 'client-documents/1/a.pdf', 'the contents');

        $this->artisan('storage:migrate', ['--from' => 'local', '--to' => 'cloudflare'])
            ->assertExitCode(0);

        Storage::disk('cloudflare')->assertExists('client-documents/1/a.pdf');
        $this->assertSame('the contents', Storage::disk('cloudflare')->get('client-documents/1/a.pdf'));
        $this->assertSame('cloudflare', $document->fresh()->disk);

        // The original is deliberately left behind as a fallback.
        Storage::disk('local')->assertExists('client-documents/1/a.pdf');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        Storage::fake('local');
        Storage::fake('cloudflare');

        $document = $this->makeDocument('local', 'client-documents/1/a.pdf');

        $this->artisan('storage:migrate', ['--from' => 'local', '--to' => 'cloudflare', '--dry-run' => true])
            ->assertExitCode(0);

        Storage::disk('cloudflare')->assertMissing('client-documents/1/a.pdf');
        $this->assertSame('local', $document->fresh()->disk);
    }

    public function test_a_record_whose_file_is_missing_is_left_pointing_at_the_old_disk(): void
    {
        Storage::fake('local');
        Storage::fake('cloudflare');

        $document = $this->makeDocument('local', 'client-documents/1/a.pdf');
        Storage::disk('local')->delete('client-documents/1/a.pdf');

        // A file that cannot be copied must not be reported as migrated.
        $this->artisan('storage:migrate', ['--from' => 'local', '--to' => 'cloudflare'])
            ->assertExitCode(1);

        $this->assertSame('local', $document->fresh()->disk);
    }

    public function test_the_file_manager_drive_moves_under_the_prefix(): void
    {
        Storage::fake('file_manager');
        Storage::fake('cloudflare');

        Storage::disk('file_manager')->put('Clients/brief.pdf', 'drive file');

        $this->artisan('storage:migrate', ['--from' => 'local', '--to' => 'cloudflare', '--only' => 'file-manager'])
            ->assertExitCode(0);

        Storage::disk('cloudflare')->assertExists('file-manager/Clients/brief.pdf');
        $this->assertSame('drive file', Storage::disk('cloudflare')->get('file-manager/Clients/brief.pdf'));
    }

    public function test_migrating_a_disk_onto_itself_is_refused(): void
    {
        $this->artisan('storage:migrate', ['--from' => 'local', '--to' => 'local'])
            ->expectsOutputToContain('nothing to do')
            ->assertExitCode(1);
    }

    public function test_rerunning_after_a_partial_copy_is_safe(): void
    {
        Storage::fake('local');
        Storage::fake('cloudflare');

        $document = $this->makeDocument('local', 'client-documents/1/a.pdf', 'the contents');
        // As if an earlier run copied the bytes but died before repointing.
        Storage::disk('cloudflare')->put('client-documents/1/a.pdf', 'the contents');

        $this->artisan('storage:migrate', ['--from' => 'local', '--to' => 'cloudflare'])
            ->assertExitCode(0);

        $this->assertSame('cloudflare', $document->fresh()->disk);
    }
}
