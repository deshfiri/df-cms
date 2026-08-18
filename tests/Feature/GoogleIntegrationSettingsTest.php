<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Google\GoogleIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Booking already creates the calendar event and stores the Meet link; it just
 * no-ops without credentials. These cover the settings that supply them, and
 * the rule that OAuth wins over the manual service-account fallback.
 */
class GoogleIntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private GoogleIntegrationSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // The real .env carries a working service-account setup, which would
        // otherwise make "nothing is configured" true only on a fresh machine.
        config([
            'services.google_calendar.credentials_path'  => null,
            'services.google_calendar.calendar_id'       => 'primary',
            'services.google_calendar.impersonate_email' => null,
        ]);

        $this->settings = app(GoogleIntegrationSettings::class);
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user->fresh();
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_only_a_super_admin_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('settings.google'))
            ->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->get(route('settings.google'))
            ->assertOk();
    }

    public function test_the_oauth_callback_is_not_open_to_everyone(): void
    {
        // It is a plain GET that Google redirects to, so it must still refuse
        // anyone who is not an administrator.
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('settings.google.callback', ['code' => 'anything']))
            ->assertForbidden();
    }

    // ── Storing credentials ──────────────────────────────────────────────

    public function test_the_client_secret_is_stored_encrypted(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.google.update'), [
                'client_id'     => 'abc.apps.googleusercontent.com',
                'client_secret' => 'GOCSPX-super-secret',
            ])
            ->assertRedirect();

        $stored = Setting::where('key', GoogleIntegrationSettings::KEY_CLIENT_SECRET)->value('value');

        $this->assertNotSame('GOCSPX-super-secret', $stored);
        $this->assertSame('GOCSPX-super-secret', Crypt::decryptString($stored));
        $this->assertSame('GOCSPX-super-secret', $this->settings->clientSecret());
    }

    public function test_submitting_a_blank_secret_keeps_the_stored_one(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('settings.google.update'), [
            'client_id'     => 'abc.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-original',
        ]);

        // The form never renders the secret back, so an empty field means
        // "unchanged", not "clear it".
        $this->actingAs($admin)->post(route('settings.google.update'), [
            'client_id'   => 'abc.apps.googleusercontent.com',
            'calendar_id' => 'primary',
        ]);

        $this->assertSame('GOCSPX-original', $this->settings->clientSecret());
    }

    public function test_a_file_that_is_not_a_service_account_key_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('creds.json', json_encode(['type' => 'authorized_user']));

        $this->actingAs($this->superAdmin())
            ->post(route('settings.google.update'), ['service_account' => $file])
            ->assertSessionHasErrors('service_account');

        $this->assertNull($this->settings->serviceAccountPath());
    }

    public function test_a_service_account_key_is_stored_off_the_public_disk(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'key.json',
            json_encode(['type' => 'service_account', 'client_email' => 'x@y.iam.gserviceaccount.com'])
        );

        $this->actingAs($this->superAdmin())
            ->post(route('settings.google.update'), ['service_account' => $file])
            ->assertSessionHasNoErrors();

        Storage::disk('local')->assertExists('google/service-account.json');
        $this->assertSame('google/service-account.json', Setting::get(GoogleIntegrationSettings::KEY_SERVICE_ACCOUNT));
    }

    // ── Which mechanism wins ─────────────────────────────────────────────

    public function test_nothing_configured_means_not_configured(): void
    {
        $this->assertFalse($this->settings->isConfigured());
        $this->assertNull($this->settings->activeMode());
    }

    public function test_client_credentials_alone_are_not_a_connection(): void
    {
        $this->settings->putClientCredentials('abc', 'secret');

        // Without the refresh token from the consent screen there is no way to
        // act on anyone's behalf.
        $this->assertTrue($this->settings->hasOauthCredentials());
        $this->assertFalse($this->settings->isOauthConnected());
        $this->assertFalse($this->settings->isConfigured());
    }

    public function test_a_completed_oauth_connection_is_used(): void
    {
        $this->settings->putClientCredentials('abc', 'secret');
        $this->settings->putConnection('refresh-token', 'bookings@example.com');

        $this->assertSame(GoogleIntegrationSettings::MODE_OAUTH, $this->settings->activeMode());
        $this->assertSame('bookings@example.com', $this->settings->connectedAccount());
        $this->assertTrue($this->settings->isConfigured());
    }

    public function test_oauth_takes_precedence_over_the_manual_key_file(): void
    {
        Storage::disk('local')->put('google/service-account.json', json_encode(['type' => 'service_account']));
        $this->settings->putServiceAccountPath('google/service-account.json');

        $this->assertSame(GoogleIntegrationSettings::MODE_SERVICE_ACCOUNT, $this->settings->activeMode());

        $this->settings->putClientCredentials('abc', 'secret');
        $this->settings->putConnection('refresh-token', 'a@b.com');

        $this->assertSame(GoogleIntegrationSettings::MODE_OAUTH, $this->settings->activeMode());
    }

    public function test_disconnecting_falls_back_to_the_key_file(): void
    {
        Storage::disk('local')->put('google/service-account.json', json_encode(['type' => 'service_account']));
        $this->settings->putServiceAccountPath('google/service-account.json');
        $this->settings->putClientCredentials('abc', 'secret');
        $this->settings->putConnection('refresh-token', 'a@b.com');

        $this->actingAs($this->superAdmin())
            ->post(route('settings.google.disconnect'))
            ->assertRedirect();

        $this->assertFalse($this->settings->isOauthConnected());
        $this->assertSame(GoogleIntegrationSettings::MODE_SERVICE_ACCOUNT, $this->settings->activeMode());
    }

    public function test_a_missing_key_file_does_not_count_as_configured(): void
    {
        // The settings row survives a file that was deleted underneath it.
        $this->settings->putServiceAccountPath('google/service-account.json');

        $this->assertNull($this->settings->serviceAccountPath());
        $this->assertFalse($this->settings->isConfigured());
    }

    public function test_connecting_without_credentials_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('settings.google.connect'))
            ->assertSessionHasErrors('client_id');
    }

    public function test_the_calendar_id_defaults_to_primary(): void
    {
        $this->assertSame('primary', $this->settings->calendarId());

        $this->settings->putCalendarId('bookings@group.calendar.google.com');

        $this->assertSame('bookings@group.calendar.google.com', $this->settings->calendarId());
    }
}
