<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\FileManagerService;
use App\Services\Storage\StorageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The shared drive following the storage provider.
 *
 * It has no per-file database record, so unlike everything else it can only
 * show one disk at a time — which makes "self-hosted keeps using its own disk,
 * a CDN gets a prefix" the contract worth pinning down.
 */
class FileManagerStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view file-manager', 'manage file-manager'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function user(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view file-manager', 'manage file-manager']);

        return $user->fresh();
    }

    private function activateR2(): void
    {
        Setting::set(StorageSettings::KEY_R2_ACCOUNT_ID, 'acct123');
        Setting::set(StorageSettings::KEY_R2_ACCESS_KEY, 'key123');
        Setting::set(StorageSettings::KEY_R2_SECRET, Crypt::encryptString('secret123'));
        Setting::set(StorageSettings::KEY_R2_BUCKET, 'dfcp-files');
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);
    }

    // ── Which disk the drive uses ────────────────────────────────────────

    public function test_the_drive_stays_on_its_own_local_disk_while_self_hosted(): void
    {
        $this->assertSame('file_manager', app(FileManagerService::class)->diskName());
    }

    public function test_the_drive_follows_the_provider_once_one_is_active(): void
    {
        $this->activateR2();

        $this->assertSame('cloudflare', app(FileManagerService::class)->diskName());
    }

    // ── Uploading ────────────────────────────────────────────────────────

    public function test_an_upload_lands_on_the_local_drive_with_no_prefix(): void
    {
        Storage::fake('file_manager');

        $this->actingAs($this->user())
            ->post(route('file-manager.upload'), [
                'path' => 'Clients',
                'file' => UploadedFile::fake()->create('brief.pdf', 8),
            ])
            ->assertOk();

        Storage::disk('file_manager')->assertExists('Clients/brief.pdf');
    }

    public function test_an_upload_lands_under_the_file_manager_prefix_on_a_cdn(): void
    {
        Storage::fake('cloudflare');
        $this->activateR2();

        $this->actingAs($this->user())
            ->post(route('file-manager.upload'), [
                'path' => 'Clients',
                'file' => UploadedFile::fake()->create('brief.pdf', 8),
            ])
            ->assertOk();

        // Prefixed, so the drive cannot collide with client-documents/ or chat/
        // in the same bucket.
        Storage::disk('cloudflare')->assertExists('file-manager/Clients/brief.pdf');
    }

    // ── Listing ──────────────────────────────────────────────────────────

    public function test_listing_hides_the_prefix_from_the_paths_it_returns(): void
    {
        Storage::fake('cloudflare');
        $this->activateR2();

        Storage::disk('cloudflare')->put('file-manager/Clients/brief.pdf', 'x');

        $this->actingAs($this->user())
            ->getJson(route('file-manager.list', ['path' => 'Clients']))
            ->assertOk()
            // The UI works in drive-relative paths; the bucket prefix is ours.
            ->assertJsonPath('items.0.path', 'Clients/brief.pdf')
            ->assertJsonPath('path', 'Clients');
    }

    public function test_an_empty_folder_survives_on_a_cdn_via_a_placeholder(): void
    {
        Storage::fake('cloudflare');
        $this->activateR2();

        $this->actingAs($this->user())
            ->postJson(route('file-manager.folder.create'), ['path' => '', 'name' => 'Archive'])
            ->assertOk();

        // Object stores have no empty directories, so one is held open.
        Storage::disk('cloudflare')->assertExists('file-manager/Archive/.keep');

        // ...and the placeholder is never shown as a file.
        $this->actingAs($this->user())
            ->getJson(route('file-manager.list', ['path' => 'Archive']))
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    // ── Reading back ─────────────────────────────────────────────────────

    public function test_a_file_downloads_from_the_cdn_without_a_local_path(): void
    {
        Storage::fake('cloudflare');
        $this->activateR2();

        Storage::disk('cloudflare')->put('file-manager/notes.txt', 'hello from r2');

        $response = $this->actingAs($this->user())
            ->get(route('file-manager.download', ['path' => 'notes.txt']))
            ->assertOk();

        $this->assertSame('hello from r2', $response->streamedContent());
    }

    public function test_the_keep_placeholder_cannot_be_downloaded(): void
    {
        Storage::fake('cloudflare');
        $this->activateR2();

        Storage::disk('cloudflare')->put('file-manager/Archive/.keep', 'x');

        $this->actingAs($this->user())
            ->get(route('file-manager.download', ['path' => 'Archive/.keep']))
            ->assertNotFound();
    }

    public function test_path_traversal_is_still_refused_on_a_cdn(): void
    {
        Storage::fake('cloudflare');
        Storage::fake('local');
        $this->activateR2();

        Storage::disk('local')->put('secret.txt', 'not yours');

        $this->actingAs($this->user())
            ->get(route('file-manager.download', ['path' => '../../secret.txt']))
            ->assertNotFound();
    }

    // ── Deleting ─────────────────────────────────────────────────────────

    public function test_deleting_a_folder_removes_it_from_the_cdn(): void
    {
        Storage::fake('cloudflare');
        $this->activateR2();

        Storage::disk('cloudflare')->put('file-manager/Old/brief.pdf', 'x');

        $this->actingAs($this->user())
            ->deleteJson(route('file-manager.destroy'), ['path' => 'Old'])
            ->assertOk();

        Storage::disk('cloudflare')->assertMissing('file-manager/Old/brief.pdf');
    }
}
