<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\DocumentType;
use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\Storage\StorageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Where uploads go, and — more importantly — where they are read back from.
 *
 * The promise this module makes is that changing provider never strands a file
 * that already exists. Most of these tests exist to hold that line.
 */
class StorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user->fresh();
    }

    private function settings(): StorageSettings
    {
        return app(StorageSettings::class);
    }

    /** Fully valid R2 credentials, straight into settings. */
    private function configureR2(): void
    {
        Setting::set(StorageSettings::KEY_R2_ACCOUNT_ID, 'acct123');
        Setting::set(StorageSettings::KEY_R2_ACCESS_KEY, 'key123');
        Setting::set(StorageSettings::KEY_R2_SECRET, Crypt::encryptString('secret123'));
        Setting::set(StorageSettings::KEY_R2_BUCKET, 'dfcp-files');
    }

    // ── Which disk is active ─────────────────────────────────────────────

    public function test_storage_is_self_hosted_when_nothing_is_configured(): void
    {
        $this->assertSame('local', $this->settings()->activeDisk());
        $this->assertFalse($this->settings()->usingCdn());
    }

    public function test_a_selected_provider_with_incomplete_credentials_falls_back_to_local(): void
    {
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);
        Setting::set(StorageSettings::KEY_R2_ACCOUNT_ID, 'acct123');
        // No access key, secret or bucket — half-entered is not connected.

        $this->assertSame('local', $this->settings()->activeDisk());
    }

    public function test_a_fully_configured_provider_becomes_the_active_disk(): void
    {
        $this->configureR2();
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);

        $this->assertSame('cloudflare', $this->settings()->activeDisk());
        $this->assertTrue($this->settings()->usingCdn());
    }

    public function test_an_unknown_provider_value_is_treated_as_local(): void
    {
        Setting::set(StorageSettings::KEY_PROVIDER, 'dropbox');

        $this->assertSame(StorageSettings::PROVIDER_LOCAL, $this->settings()->provider());
        $this->assertSame('local', $this->settings()->activeDisk());
    }

    // ── Uploads follow the active disk, reads follow the file ────────────

    public function test_an_upload_records_the_disk_it_was_written_to(): void
    {
        Storage::fake('cloudflare');
        $this->configureR2();
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);

        $this->actingAs($user = $this->superAdmin());

        $document = app(DocumentService::class)->uploadClientDocument(
            $this->makeClient(),
            UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
            ['document_type_id' => $this->makeDocumentType()->id, 'title' => 'Contract'],
        );

        $this->assertSame('cloudflare', $document->disk);
        Storage::disk('cloudflare')->assertExists($document->path);
    }

    /**
     * The whole point of the `disk` column: a file uploaded before the switch
     * must still be found afterwards.
     */
    public function test_a_file_stored_before_a_switch_is_still_read_from_its_own_disk(): void
    {
        Storage::fake('local');
        Storage::fake('cloudflare');

        $this->actingAs($this->superAdmin());
        $client = $this->makeClient();

        // Uploaded while self-hosted.
        $document = app(DocumentService::class)->uploadClientDocument(
            $client,
            UploadedFile::fake()->create('early.pdf', 12, 'application/pdf'),
            ['document_type_id' => $this->makeDocumentType()->id, 'title' => 'Early'],
        );
        $this->assertSame('local', $document->disk);

        // Provider switched afterwards.
        $this->configureR2();
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);

        $this->assertSame('cloudflare', $this->settings()->activeDisk());

        // The old file is still served, and was never copied to the new disk.
        $this->get(route('clients.documents.download', [$client, $document]))->assertOk();
        Storage::disk('cloudflare')->assertMissing($document->path);
    }

    // ── The settings screen ──────────────────────────────────────────────

    public function test_only_a_super_admin_may_open_storage_settings(): void
    {
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('Manager');

        $this->actingAs($manager)->get(route('settings.storage'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('settings.storage'))->assertOk();
    }

    /**
     * All four settings pages share one nav partial now, so a broken include
     * or an unclosed wrapper would take the whole section down at once.
     */
    public function test_every_settings_page_renders_with_the_shared_nav(): void
    {
        $this->actingAs($this->superAdmin());

        foreach (['settings.index', 'settings.storage', 'settings.google', 'settings.meta'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('Storage &amp; CDN', false);
        }
    }

    public function test_the_nav_reports_the_active_provider_on_every_page(): void
    {
        $this->configureR2();
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);

        $this->actingAs($this->superAdmin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('set-nav-badge', false)
            ->assertSee('R2');
    }

    /**
     * The panel a save came from must reopen.
     *
     * Otherwise the page returns showing the *active* provider's panel, so an
     * admin who just saved Cloudinary is looking at the local one — with the
     * Activate button they still need, and any field errors, both hidden. That
     * reads as "I connected it and nothing happened".
     */
    public function test_saving_reopens_the_panel_it_was_saved_from(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.storage.update'), [
                'provider'   => 'cloudinary',
                'cloud_name' => 'demo-cloud',
                'api_key'    => '123',
                'api_secret' => 'shh',
            ])
            ->assertSessionHas('panel', 'cloudinary');
    }

    public function test_a_validation_failure_returns_the_provider_so_its_panel_reopens(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.storage.update'), [
                'provider'   => 'cloudinary',
                'cloud_name' => '',   // required
                'api_key'    => '',   // required
            ])
            ->assertSessionHasErrors(['cloud_name', 'api_key'])
            // old('provider') is what the page reads to reopen the right panel.
            ->assertSessionHasInput('provider', 'cloudinary');
    }

    /**
     * Credentials on file for an idle provider is the confusing state; the page
     * has to say so rather than leaving it to be discovered.
     */
    public function test_the_page_warns_when_a_configured_provider_is_not_in_use(): void
    {
        Setting::set(StorageSettings::KEY_CLOUDINARY_CLOUD, 'demo-cloud');
        Setting::set(StorageSettings::KEY_CLOUDINARY_KEY, '123');
        Setting::set(StorageSettings::KEY_CLOUDINARY_SECRET, Crypt::encryptString('shh'));

        $this->assertSame('local', $this->settings()->activeDisk());

        $this->actingAs($this->superAdmin())
            ->get(route('settings.storage'))
            ->assertOk()
            ->assertSee('credentials saved but', false)
            ->assertSee('not in use', false);
    }

    public function test_no_warning_once_the_configured_provider_is_active(): void
    {
        $this->configureR2();
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);

        $this->actingAs($this->superAdmin())
            ->get(route('settings.storage'))
            ->assertOk()
            ->assertDontSee('credentials saved but', false);
    }

    public function test_credentials_are_stored_encrypted_and_never_rendered_back(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.storage.update'), [
                'provider'   => 'cloudflare',
                'account_id' => 'acct123',
                'access_key' => 'key123',
                'secret'     => 'super-secret-value',
                'bucket'     => 'dfcp-files',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $stored = Setting::get(StorageSettings::KEY_R2_SECRET);
        $this->assertNotSame('super-secret-value', $stored);
        $this->assertSame('super-secret-value', Crypt::decryptString($stored));

        $this->actingAs($this->superAdmin())
            ->get(route('settings.storage'))
            ->assertOk()
            ->assertDontSee('super-secret-value');
    }

    public function test_saving_with_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->configureR2();

        $this->actingAs($this->superAdmin())
            ->post(route('settings.storage.update'), [
                'provider'   => 'cloudflare',
                'account_id' => 'acct123',
                'access_key' => 'key123',
                'secret'     => '',
                'bucket'     => 'renamed-bucket',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('renamed-bucket', Setting::get(StorageSettings::KEY_R2_BUCKET));
        $this->assertSame('secret123', Crypt::decryptString(Setting::get(StorageSettings::KEY_R2_SECRET)));
    }

    public function test_saving_credentials_does_not_by_itself_activate_the_provider(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.storage.update'), [
                'provider'   => 'cloudflare',
                'account_id' => 'acct123',
                'access_key' => 'key123',
                'secret'     => 'secret123',
                'bucket'     => 'dfcp-files',
            ]);

        // Entering credentials is not the same as trusting them with uploads.
        $this->assertSame('local', $this->settings()->activeDisk());
    }

    public function test_a_provider_cannot_be_activated_before_its_credentials_are_saved(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.storage.activate'), ['provider' => 'cloudinary'])
            ->assertSessionHasErrors('storage');

        $this->assertSame('local', $this->settings()->activeDisk());
    }

    public function test_switching_back_to_self_hosted_is_never_blocked(): void
    {
        $this->configureR2();
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);

        $this->actingAs($this->superAdmin())
            ->post(route('settings.storage.activate'), ['provider' => 'local'])
            ->assertSessionHasNoErrors();

        $this->assertSame('local', $this->settings()->activeDisk());
    }

    public function test_disconnecting_the_active_provider_returns_uploads_to_local(): void
    {
        $this->configureR2();
        Setting::set(StorageSettings::KEY_PROVIDER, StorageSettings::PROVIDER_CLOUDFLARE);

        $this->actingAs($this->superAdmin())
            ->post(route('settings.storage.disconnect'), ['provider' => 'cloudflare'])
            ->assertSessionHasNoErrors();

        $this->assertSame('local', $this->settings()->activeDisk());
        $this->assertNull(Setting::get(StorageSettings::KEY_R2_SECRET));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function makeClient(): Client
    {
        $category = Category::create(['name' => 'Storage Cat', 'slug' => 'storage-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'Storage Client',
            'brand_name'  => 'Storage Brand',
            'category_id' => $category->id,
        ]);
    }

    /** Unique name per call — the slug is derived from it and is unique. */
    private function makeDocumentType(): DocumentType
    {
        return DocumentType::create(['name' => 'Storage Type ' . uniqid(), 'is_active' => true]);
    }
}
