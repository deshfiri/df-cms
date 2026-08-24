<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Storage\Cloudinary\CloudinaryClient;
use App\Services\Storage\StorageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cloudinary behind Flysystem.
 *
 * The signature is the part worth pinning down: Cloudinary rejects a request
 * whose signed parameters do not match byte-for-byte what was posted, and the
 * failure looks like bad credentials rather than bad signing.
 */
class CloudinaryStorageTest extends TestCase
{
    use RefreshDatabase;

    private function configure(?string $folder = null): void
    {
        Setting::set(StorageSettings::KEY_CLOUDINARY_CLOUD, 'demo-cloud');
        Setting::set(StorageSettings::KEY_CLOUDINARY_KEY, '123456789');
        Setting::set(StorageSettings::KEY_CLOUDINARY_SECRET, Crypt::encryptString('topsecret'));
        Setting::set(StorageSettings::KEY_CLOUDINARY_FOLDER, $folder);
    }

    private function client(?string $folder = null): CloudinaryClient
    {
        return new CloudinaryClient('demo-cloud', '123456789', 'topsecret', $folder);
    }

    public function test_the_delivery_url_is_built_from_the_stored_path(): void
    {
        $this->assertSame(
            'https://res.cloudinary.com/demo-cloud/raw/upload/client-documents/7/abc.pdf',
            $this->client()->url('client-documents/7/abc.pdf'),
        );
    }

    public function test_a_configured_folder_prefixes_the_public_id(): void
    {
        $this->assertSame(
            'https://res.cloudinary.com/demo-cloud/raw/upload/dfcp/chat/1/note.ogg',
            $this->client('dfcp')->url('chat/1/note.ogg'),
        );

        // ...and comes back off again when a listing is turned into paths.
        $this->assertSame('chat/1/note.ogg', $this->client('dfcp')->toPath('dfcp/chat/1/note.ogg'));
    }

    public function test_an_upload_is_signed_over_exactly_the_parameters_it_sends(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['public_id' => 'a/b.txt'], 200)]);

        $this->client()->upload('a/b.txt', 'hello');

        Http::assertSent(function ($request) {
            // Multipart parts arrive as [['name' => ..., 'contents' => ...], ...].
            $parts = collect($request->data())->pluck('contents', 'name');

            // Rebuild the signature independently: the signed parameters sorted
            // by name, joined as a query string, secret appended, sha1. Anything
            // sent but left out of this string is a request Cloudinary rejects.
            $expected = sha1(
                'invalidate=true&overwrite=true&public_id=a/b.txt&timestamp=' . $parts['timestamp'] . 'topsecret'
            );

            return str_contains($request->url(), '/demo-cloud/raw/upload')
                && $parts['public_id'] === 'a/b.txt'
                && $parts['api_key'] === '123456789'
                && $parts['signature'] === $expected;
        });
    }

    public function test_the_signed_parameters_are_exactly_those_that_are_posted(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['public_id' => 'a/b.txt'], 200)]);

        $this->client()->upload('a/b.txt', 'hello');

        Http::assertSent(function ($request) {
            $names = collect($request->data())->pluck('name')->sort()->values()->all();

            // api_key, signature and file are the three Cloudinary excludes from
            // signing; everything else posted must be in the signature above.
            $this->assertSame(
                ['api_key', 'file', 'invalidate', 'overwrite', 'public_id', 'signature', 'timestamp'],
                $names,
            );

            return true;
        });
    }

    public function test_a_missing_asset_reports_as_absent_rather_than_throwing(): void
    {
        Http::fake(['res.cloudinary.com/*' => Http::response('Not found', 404)]);

        $this->assertNull($this->client()->head('nope.pdf'));
    }

    /**
     * The delivery edge caches 404s.
     *
     * Anything that checks a path before writing to it — picking a
     * non-colliding filename, for instance — teaches the CDN that the path is
     * missing, and that cached miss outlives the upload that follows. Trusting
     * it would make a file that was just stored read as lost, which is exactly
     * what happened to the File Manager. The Admin API is what settles it.
     */
    public function test_a_cached_cdn_miss_does_not_make_a_stored_file_look_absent(): void
    {
        $this->configure();

        Http::fake([
            // The edge still remembers the miss from before the upload...
            'res.cloudinary.com/*' => Http::response('Not found', 404),
            // ...while the Admin API knows the asset is there.
            'api.cloudinary.com/*/resources/raw/upload/*' => Http::response([
                'public_id' => 'a/b.txt',
                'bytes'     => 512,
                'secure_url' => 'https://res.cloudinary.com/demo-cloud/raw/upload/v123/a/b.txt',
            ], 200),
        ]);

        $this->assertTrue(Storage::disk('cloudinary')->exists('a/b.txt'));
        $this->assertSame(512, Storage::disk('cloudinary')->size('a/b.txt'));
    }

    public function test_an_asset_absent_from_both_the_cdn_and_the_admin_api_is_absent(): void
    {
        $this->configure();

        Http::fake([
            'res.cloudinary.com/*' => Http::response('Not found', 404),
            'api.cloudinary.com/*' => Http::response(['error' => ['message' => 'Resource not found']], 404),
        ]);

        $this->assertFalse(Storage::disk('cloudinary')->exists('gone.txt'));
    }

    /** A read falls back to the versioned URL, which carries no cached miss. */
    public function test_a_read_falls_back_to_the_versioned_url(): void
    {
        $this->configure();

        Http::fake([
            // The unversioned delivery path is the one holding the cached 404.
            'res.cloudinary.com/demo-cloud/raw/upload/a/b.txt' => Http::response('', 404),
            'api.cloudinary.com/*' => Http::response([
                'public_id'  => 'a/b.txt',
                'secure_url' => 'https://res.cloudinary.com/demo-cloud/raw/upload/v999/a/b.txt',
            ], 200),
            'res.cloudinary.com/demo-cloud/raw/upload/v999/a/b.txt' => Http::response('the real bytes', 200),
        ]);

        $this->assertSame('the real bytes', Storage::disk('cloudinary')->get('a/b.txt'));
    }

    public function test_metadata_comes_from_the_delivery_cdn_headers(): void
    {
        Http::fake(['res.cloudinary.com/*' => Http::response('', 200, [
            'Content-Length' => '2048',
            'Content-Type'   => 'application/pdf',
        ])]);

        $headers = $this->client()->head('a/b.pdf');

        $this->assertSame('2048', $headers['size']);
        $this->assertSame('application/pdf', $headers['mime']);
    }

    // ── Registered as a real disk ────────────────────────────────────────

    public function test_the_disk_resolves_and_reports_existence_through_the_adapter(): void
    {
        $this->configure();
        Http::fake(['res.cloudinary.com/*' => Http::response('', 200, ['Content-Length' => '5'])]);

        $this->assertTrue(Storage::disk('cloudinary')->exists('a/b.txt'));
        $this->assertSame(5, Storage::disk('cloudinary')->size('a/b.txt'));
    }

    public function test_storage_url_returns_the_cdn_link(): void
    {
        $this->configure('dfcp');

        $this->assertSame(
            'https://res.cloudinary.com/demo-cloud/raw/upload/dfcp/a/b.txt',
            Storage::disk('cloudinary')->url('a/b.txt'),
        );
    }

    /**
     * config/filesystems.php carries env defaults for these disks. A value
     * entered in Settings has to beat them, or a stale .env on one server would
     * quietly send that server's uploads to a different cloud.
     */
    public function test_saved_settings_beat_the_env_defaults(): void
    {
        config([
            'filesystems.disks.cloudinary.cloud_name' => 'env-cloud',
            'filesystems.disks.cloudinary.api_key'    => 'env-key',
            'filesystems.disks.cloudinary.api_secret' => 'env-secret',
        ]);

        $this->configure();

        $this->assertSame(
            'https://res.cloudinary.com/demo-cloud/raw/upload/a/b.txt',
            Storage::disk('cloudinary')->url('a/b.txt'),
        );
    }

    public function test_env_defaults_are_used_when_nothing_is_saved(): void
    {
        config([
            'filesystems.disks.cloudinary.cloud_name' => 'env-cloud',
            'filesystems.disks.cloudinary.api_key'    => 'env-key',
            'filesystems.disks.cloudinary.api_secret' => 'env-secret',
        ]);

        $this->assertSame(
            'https://res.cloudinary.com/env-cloud/raw/upload/a/b.txt',
            Storage::disk('cloudinary')->url('a/b.txt'),
        );
    }

    public function test_resolving_the_disk_without_credentials_fails_loudly(): void
    {
        // No settings written — a silent no-op disk would lose files.
        $this->expectExceptionMessage('Cloudinary is selected but its credentials are incomplete');

        Storage::disk('cloudinary')->exists('a/b.txt');
    }
}
