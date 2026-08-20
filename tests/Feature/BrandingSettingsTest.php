<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Logo and favicon uploads. The two share one code path, so the favicon tests
 * are also a regression net for the logo.
 */
class BrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user->fresh();
    }

    protected function tearDown(): void
    {
        // These write into public/, not a faked disk.
        foreach (['uploads/logo', 'uploads/favicon'] as $dir) {
            foreach (glob(public_path($dir . '/*')) ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_a_favicon_can_be_uploaded(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), [
                'favicon' => UploadedFile::fake()->image('icon.png', 32, 32),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $stored = Setting::get('app_favicon');

        $this->assertNotNull($stored);
        $this->assertStringStartsWith('uploads/favicon/favicon_', $stored);
        $this->assertFileExists(public_path($stored));
    }

    public function test_an_ico_file_is_accepted(): void
    {
        // The `image` validation rule rejects .ico, which is exactly what most
        // people have to hand — the favicon field must not use it.
        $ico = UploadedFile::fake()->create('site.ico', 8, 'image/vnd.microsoft.icon');

        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['favicon' => $ico])
            ->assertSessionHasNoErrors();

        $this->assertStringEndsWith('.ico', (string) Setting::get('app_favicon'));
    }

    public function test_a_non_image_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), [
                'favicon' => UploadedFile::fake()->create('payload.php', 4, 'application/x-php'),
            ])
            ->assertSessionHasErrors('favicon');

        $this->assertNull(Setting::get('app_favicon'));
    }

    public function test_replacing_a_favicon_removes_the_previous_file(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('settings.update'), [
            'favicon' => UploadedFile::fake()->image('first.png', 32, 32),
        ]);
        $first = Setting::get('app_favicon');

        // Timestamped filenames — without a gap the replacement can reuse the name.
        sleep(1);

        $this->actingAs($admin)->post(route('settings.update'), [
            'favicon' => UploadedFile::fake()->image('second.png', 32, 32),
        ]);
        $second = Setting::get('app_favicon');

        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist(public_path($first));
        $this->assertFileExists(public_path($second));
    }

    public function test_a_favicon_can_be_removed(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('settings.update'), [
            'favicon' => UploadedFile::fake()->image('icon.png', 32, 32),
        ]);
        $path = Setting::get('app_favicon');

        $this->actingAs($admin)->post(route('settings.update'), ['remove_favicon' => 1]);

        $this->assertNull(Setting::get('app_favicon'));
        $this->assertFileDoesNotExist(public_path($path));
    }

    public function test_the_favicon_is_rendered_in_the_page_head(): void
    {
        $admin = $this->superAdmin();

        // Any page in the layout proves it; the dashboard's chart SQL uses
        // MONTH(), which the SQLite test driver does not implement.
        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee('rel="icon"', false);

        $this->actingAs($admin)->post(route('settings.update'), [
            'favicon' => UploadedFile::fake()->image('icon.png', 32, 32),
        ]);

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('rel="icon"', false)
            ->assertSee(Setting::get('app_favicon'), false);
    }

    public function test_uploading_a_favicon_leaves_the_logo_alone(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('settings.update'), [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
        ]);
        $logo = Setting::get('app_logo');

        $this->actingAs($admin)->post(route('settings.update'), [
            'favicon' => UploadedFile::fake()->image('icon.png', 32, 32),
        ]);

        $this->assertSame($logo, Setting::get('app_logo'));
        $this->assertFileExists(public_path($logo));
    }

    public function test_only_a_super_admin_can_change_branding(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->post(route('settings.update'), [
                'favicon' => UploadedFile::fake()->image('icon.png', 32, 32),
            ])
            ->assertForbidden();

        $this->assertNull(Setting::get('app_favicon'));
    }
}
