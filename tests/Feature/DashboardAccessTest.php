<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The dashboard reports on the company, so it takes a permission of its own.
 *
 * Without 'view dashboard' there is no menu item and no page: staff land on
 * their own work instead, which is all the app has to tell them.
 */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view dashboard', 'guard_name' => 'web']);
    }

    public function test_without_the_permission_the_dashboard_lands_on_my_work(): void
    {
        $staff = User::factory()->create(['is_active' => true]);

        $this->actingAs($staff)->get(route('dashboard'))->assertRedirect(route('my-work'));
        $this->actingAs($staff)->get(route('my-work'))->assertOk()->assertSee('My Work');
    }

    public function test_without_the_permission_there_is_no_dashboard_menu_item(): void
    {
        $staff = User::factory()->create(['is_active' => true]);

        $this->actingAs($staff)->get(route('my-work'))
            ->assertOk()
            ->assertDontSee('<span class="sb-lbl">Dashboard</span>', false)
            ->assertSee('<span class="sb-lbl">My Work</span>', false);
    }

    public function test_with_the_permission_the_menu_item_and_the_page_are_there(): void
    {
        $granted = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view dashboard')->fresh();

        $this->actingAs($granted)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<span class="sb-lbl">Dashboard</span>', false);
    }

    /**
     * The Manager view of the dashboard is not exercised here: it groups by
     * MONTH(), which only exists on MySQL, so it cannot render against the
     * SQLite test database. The case above — granted, therefore not redirected
     * — is the part of the rule this file owns.
     */

    public function test_the_menu_never_names_the_same_page_twice(): void
    {
        $staff = User::factory()->create(['is_active' => true]);

        $html = $this->actingAs($staff)->get(route('my-work'))->assertOk()->getContent();
        preg_match_all('/<span class="sb-lbl">([^<]+)<\/span>/', $html, $matches);

        $duplicated = array_keys(array_filter(array_count_values($matches[1]), fn ($n) => $n > 1));

        $this->assertSame([], $duplicated, 'these menu items appear more than once: ' . implode(', ', $duplicated));
    }
}
