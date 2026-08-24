<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Storage\BrandingAssetService;
use App\Services\Storage\StorageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The logo and favicon.
 *
 * These are the only genuinely public uploads in the application — a browser
 * fetches them before anyone logs in — so unlike every other file they cannot
 * be proxied, and they can only live on a provider that serves public URLs.
 */
class BrandingStorageTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user->fresh();
    }

    private function activateCloudinary(): void
    {
        Setting::set(StorageSettings::KEY_CLOUDINARY_CLOUD, 'demo-cloud');
        Setting::set(StorageSettings::KEY_CLOUDINARY_KEY, '123');
        Setting::set(StorageSettings::KEY_CLOUDINARY_SECRET, Crypt::encryptString('shh'));
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDINARY);
    }

    /** R2 without a public delivery URL — a private bucket. */
    private function activateR2(?string $publicUrl = null): void
    {
        Setting::set(StorageSettings::KEY_R2_ACCOUNT_ID, 'acct');
        Setting::set(StorageSettings::KEY_R2_ACCESS_KEY, 'key');
        Setting::set(StorageSettings::KEY_R2_SECRET, Crypt::encryptString('secret'));
        Setting::set(StorageSettings::KEY_R2_BUCKET, 'bucket');
        Setting::set(StorageSettings::KEY_R2_URL, $publicUrl);
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);
    }

    protected function tearDown(): void
    {
        foreach (['uploads/logo', 'uploads/favicon'] as $dir) {
            foreach (glob(public_path($dir . '/*')) ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    // ── Which provider can host branding at all ──────────────────────────

    public function test_self_hosted_does_not_serve_public_urls(): void
    {
        $this->assertFalse(app(StorageSettings::class)->servesPublicUrls());
    }

    public function test_cloudinary_serves_public_urls(): void
    {
        $this->activateCloudinary();

        $this->assertTrue(app(StorageSettings::class)->servesPublicUrls());
    }

    /**
     * A private R2 bucket's only address is the S3 endpoint, which needs a
     * signed request — putting a logo there renders a broken image.
     */
    public function test_r2_without_a_delivery_url_does_not_serve_public_urls(): void
    {
        $this->activateR2();

        $this->assertTrue(app(StorageSettings::class)->usingCdn());
        $this->assertFalse(app(StorageSettings::class)->servesPublicUrls());
    }

    public function test_r2_with_a_delivery_url_serves_public_urls(): void
    {
        $this->activateR2('https://files.example.com');

        $this->assertTrue(app(StorageSettings::class)->servesPublicUrls());
    }

    // ── Where an upload lands ────────────────────────────────────────────

    public function test_a_logo_stays_local_while_self_hosted(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(BrandingAssetService::LOCAL, Setting::get('app_logo_disk'));
        $this->assertStringStartsWith('uploads/logo/', Setting::get('app_logo'));
        $this->assertFileExists(public_path(Setting::get('app_logo')));
    }

    public function test_a_logo_goes_to_the_cdn_once_one_can_serve_it(): void
    {
        Storage::fake('cloudinary');
        $this->activateCloudinary();

        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('cloudinary', Setting::get('app_logo_disk'));
        Storage::disk('cloudinary')->assertExists(Setting::get('app_logo'));
        // ...and nothing was written into public/.
        $this->assertSame([], glob(public_path('uploads/logo/*')) ?: []);
    }

    /**
     * The fallback that keeps the login page working. A CDN that cannot serve a
     * public URL must not take the logo, or it renders as a broken image.
     */
    public function test_a_logo_stays_local_on_a_private_r2_bucket(): void
    {
        Storage::fake('cloudflare');
        $this->activateR2();   // no delivery URL

        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertRedirect();

        $this->assertSame(BrandingAssetService::LOCAL, Setting::get('app_logo_disk'));
        Storage::disk('cloudflare')->assertDirectoryEmpty('uploads/logo');
    }

    // ── How it is rendered ───────────────────────────────────────────────

    public function test_a_local_logo_renders_through_asset(): void
    {
        Setting::set('app_logo', 'uploads/logo/logo_1.png');
        Setting::set('app_logo_disk', BrandingAssetService::LOCAL);

        $this->assertSame(asset('uploads/logo/logo_1.png'), app(BrandingAssetService::class)->url('app_logo'));
    }

    public function test_a_cdn_logo_renders_as_an_absolute_url(): void
    {
        $this->activateCloudinary();

        Setting::set('app_logo', 'uploads/logo/logo_1.png');
        Setting::set('app_logo_disk', 'cloudinary');

        $this->assertSame(
            'https://res.cloudinary.com/demo-cloud/raw/upload/uploads/logo/logo_1.png',
            app(BrandingAssetService::class)->url('app_logo'),
        );
    }

    /**
     * A value written before this existed has no `_disk` companion. It must keep
     * rendering exactly as it did rather than disappearing.
     */
    public function test_a_legacy_logo_with_no_recorded_disk_still_renders(): void
    {
        Setting::set('app_logo', 'uploads/logo/old_logo.png');
        // No app_logo_disk row at all.

        $this->assertSame(asset('uploads/logo/old_logo.png'), app(BrandingAssetService::class)->url('app_logo'));
    }

    public function test_nothing_set_renders_as_null(): void
    {
        $this->assertNull(app(BrandingAssetService::class)->url('app_logo'));
    }

    // ── Removal ──────────────────────────────────────────────────────────

    public function test_removing_a_cdn_logo_deletes_it_and_clears_both_settings(): void
    {
        Storage::fake('cloudinary');
        $this->activateCloudinary();

        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['logo' => UploadedFile::fake()->image('logo.png')]);

        $path = Setting::get('app_logo');
        Storage::disk('cloudinary')->assertExists($path);

        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['remove_logo' => 1])
            ->assertRedirect();

        Storage::disk('cloudinary')->assertMissing($path);
        $this->assertNull(Setting::get('app_logo'));
        $this->assertNull(Setting::get('app_logo_disk'));
    }

    public function test_replacing_a_logo_removes_the_previous_one(): void
    {
        Storage::fake('cloudinary');
        $this->activateCloudinary();

        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['logo' => UploadedFile::fake()->image('first.png')]);
        $first = Setting::get('app_logo');

        // Timestamped filenames, so move the clock on to guarantee a new name.
        $this->travel(2)->seconds();

        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['logo' => UploadedFile::fake()->image('second.png')]);
        $second = Setting::get('app_logo');

        $this->assertNotSame($first, $second);
        Storage::disk('cloudinary')->assertMissing($first);
        Storage::disk('cloudinary')->assertExists($second);
    }
}
