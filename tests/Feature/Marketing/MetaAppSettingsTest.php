<?php

namespace Tests\Feature\Marketing;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Meta\MetaAppSettings;
use App\Services\Meta\MetaAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Meta *app* credentials, entered in Settings.
 *
 * Distinct from a brand's access token: these identify the application, are
 * shared installation-wide, and had previously been reachable only through
 * .env — which is not somewhere an administrator can get to.
 */
class MetaAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view ads', 'manage ads', 'view clients'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // No env fallback, so the tests measure the settings themselves.
        config(['services.meta.app_id' => null, 'services.meta.app_secret' => null]);
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user->fresh();
    }

    private function brand(): Brand
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);

        return Brand::create([
            'client_id' => $client->id,
            'name'      => 'ACME',
            'slug'      => 'acme-' . uniqid(),
            'is_active' => true,
        ]);
    }

    public function test_only_a_super_admin_can_open_the_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('settings.meta'))
            ->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->get(route('settings.meta'))
            ->assertOk()
            ->assertSee('App credentials', false);
    }

    public function test_the_app_secret_is_stored_encrypted(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.meta.update'), [
                'app_id'     => '1234567890',
                'app_secret' => 'super-secret-value',
            ])
            ->assertRedirect();

        $stored = Setting::where('key', MetaAppSettings::KEY_APP_SECRET)->value('value');

        $this->assertNotSame('super-secret-value', $stored);
        $this->assertSame('super-secret-value', Crypt::decryptString($stored));
        $this->assertSame('super-secret-value', app(MetaAppSettings::class)->appSecret());
    }

    public function test_a_blank_secret_keeps_the_stored_one(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('settings.meta.update'), [
            'app_id' => '111', 'app_secret' => 'original-secret',
        ]);

        // The form never renders the secret back, so blank means "unchanged".
        $this->actingAs($admin)->post(route('settings.meta.update'), [
            'app_id' => '222', 'api_version' => 'v21.0',
        ]);

        $settings = app(MetaAppSettings::class);
        $this->assertSame('222', $settings->appId());
        $this->assertSame('original-secret', $settings->appSecret());
    }

    public function test_credentials_reach_the_authorisation_url(): void
    {
        $settings = app(MetaAppSettings::class);
        $settings->putCredentials('app-999', 'secret');
        $settings->putScopes('ads_read,business_management');

        $url = app(MetaAuthService::class)->authorizationUrl($this->brand(), 'nonce-abc');

        $this->assertStringContainsString('client_id=app-999', $url);
        $this->assertStringContainsString('ads_read', $url);
        // The secret must never travel in a URL the browser follows.
        $this->assertStringNotContainsString('secret', $url);
    }

    public function test_connecting_is_refused_until_credentials_exist(): void
    {
        $admin = $this->superAdmin();
        $brand = $this->brand();

        $this->actingAs($admin)
            ->get(route('marketing.meta.connect', $brand))
            ->assertSessionHasErrors('integration');

        app(MetaAppSettings::class)->putCredentials('app-1', 'secret-1');

        // Now it redirects out to Meta rather than complaining.
        $this->actingAs($admin)
            ->get(route('marketing.meta.connect', $brand))
            ->assertRedirectContains('facebook.com');
    }

    public function test_an_invalid_api_version_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.meta.update'), ['api_version' => '21'])
            ->assertSessionHasErrors('api_version');
    }

    public function test_env_still_works_when_nothing_is_stored(): void
    {
        config(['services.meta.app_id' => 'env-app', 'services.meta.app_secret' => 'env-secret']);

        // An existing .env deployment keeps working untouched.
        $settings = app(MetaAppSettings::class);
        $this->assertSame('env-app', $settings->appId());
        $this->assertTrue($settings->isConfigured());
    }

    public function test_stored_settings_win_over_env(): void
    {
        config(['services.meta.app_id' => 'env-app', 'services.meta.app_secret' => 'env-secret']);

        app(MetaAppSettings::class)->putCredentials('ui-app', 'ui-secret');

        $settings = app(MetaAppSettings::class);
        $this->assertSame('ui-app', $settings->appId());
        $this->assertSame('ui-secret', $settings->appSecret());
    }
}
