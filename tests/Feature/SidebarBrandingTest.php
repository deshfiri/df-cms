<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The collapsed sidebar's icon, and the colour of the active menu item.
 *
 * A collapsed sidebar has room for a square mark, not a wide logo, so it gets
 * its own upload; and the highlight on the page you are on is its own colour,
 * which follows the theme colour until it is given one.
 */
class SidebarBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        return tap(User::factory()->create(['is_active' => true]))->assignRole('Super Admin')->fresh();
    }

    protected function tearDown(): void
    {
        // Branding writes into public/, not a faked disk.
        foreach (['uploads/icon', 'uploads/favicon', 'uploads/logo'] as $dir) {
            foreach (glob(public_path($dir . '/*')) ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    // ── The collapsed icon ───────────────────────────────────────────────

    public function test_an_icon_can_be_uploaded_for_the_collapsed_sidebar(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('settings.update'), ['icon' => UploadedFile::fake()->image('mark.png', 64, 64)])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $stored = Setting::get('app_icon');
        $this->assertStringStartsWith('uploads/icon/icon_', (string) $stored);
        $this->assertFileExists(public_path($stored));

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('class="sb-brand-mark"', false)
            ->assertSee(asset($stored), false)
            // Marks the sidebar as having a mark to swap in when collapsed.
            ->assertSee('<aside id="sidebar" class="has-mark"', false);
    }

    public function test_without_an_icon_the_favicon_stands_in(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('settings.update'), ['favicon' => UploadedFile::fake()->image('fav.png', 32, 32)])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertSee('class="sb-brand-mark"', false)
            ->assertSee(asset(Setting::get('app_favicon')), false);
    }

    public function test_with_no_icon_and_no_favicon_nothing_is_swapped_in(): void
    {
        $this->actingAs($this->superAdmin())->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee('class="sb-brand-mark"', false)
            ->assertDontSee('has-mark', false);
    }

    public function test_the_icon_can_be_removed(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('settings.update'), ['icon' => UploadedFile::fake()->image('mark.png', 64, 64)]);
        $path = public_path(Setting::get('app_icon'));

        $this->actingAs($admin)->post(route('settings.update'), ['remove_icon' => 1])->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('app_icon'));
        $this->assertFileDoesNotExist($path);
    }

    public function test_an_ico_is_accepted_but_a_pdf_is_not(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('settings.update'), ['icon' => UploadedFile::fake()->create('mark.ico', 4, 'image/vnd.microsoft.icon')])
            ->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.ico', (string) Setting::get('app_icon'));

        $this->actingAs($admin)
            ->post(route('settings.update'), ['icon' => UploadedFile::fake()->create('brochure.pdf', 4, 'application/pdf')])
            ->assertSessionHasErrors('icon');
    }

    // ── The active menu item's colour ────────────────────────────────────

    public function test_the_active_menu_colour_can_be_set(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('settings.update'), ['nav_active_color' => '#FF8800'])
            ->assertSessionHasNoErrors();

        $this->assertSame('#ff8800', Setting::get('nav_active_color'));

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('--nav-active: #ff8800;', false)
            ->assertSee('--nav-active-rgb: 255, 136, 0;', false);
    }

    public function test_it_follows_the_theme_colour_until_it_is_given_one(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('settings.update'), ['theme_color' => '#059669'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('nav_active_color'));
        $this->actingAs($admin)->get(route('settings.index'))->assertSee('--nav-active: #059669;', false);
    }

    public function test_choosing_to_follow_the_theme_again_clears_the_colour(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('settings.update'), ['nav_active_color' => '#ff8800']);

        $this->actingAs($admin)
            ->post(route('settings.update'), ['nav_use_theme' => 1, 'nav_active_color' => '#ff8800'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('nav_active_color'));
    }

    public function test_a_colour_that_is_not_a_hex_code_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.update'), ['nav_active_color' => 'rebeccapurple'])
            ->assertSessionHasErrors('nav_active_color');

        $this->assertNull(Setting::get('nav_active_color'));
    }

    public function test_only_a_super_admin_may_change_branding(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->post(route('settings.update'), ['nav_active_color' => '#ff8800'])
            ->assertForbidden();
    }
}
