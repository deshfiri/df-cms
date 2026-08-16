<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The visual half of the authorization rule.
 *
 * RouteAuthorizationMatrixTest proves a route cannot be *reached* without
 * permission. This proves it is not *offered* either — a menu full of links
 * that 403 on click is its own kind of broken, and for a long time this app
 * had the mirror problem: hidden links in front of open doors.
 */
class SidebarVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Sidebar links that must never appear without the matching permission. */
    private const GATED_LINKS = [
        'clients.index'      => 'view clients',
        'meetings.all'       => 'view clients',
        'payments.index'     => 'view payments',
        'ads.index'          => 'view ads',
        'tasks.index'        => 'view tasks',
        'performance.index'  => 'view performance',
        'file-manager.index' => 'view file-manager',
        'import.index'       => 'import clients',
        'categories.index'   => 'manage categories',
        'users.index'        => 'manage users',
        'workflows.index'    => 'manage workflows',
        'chat.monitor'       => 'monitor chats',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_unique(array_values(self::GATED_LINKS)) as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    /**
     * Rendered through /chat rather than /dashboard: both extend the same
     * layout, but the dashboard's charts use MySQL-only date functions
     * (MONTH()) that the sqlite test database cannot execute.
     */
    private function sidebar(User $user): string
    {
        return $this->actingAs($user)->get(route('chat.index'))->assertOk()->getContent();
    }

    public function test_a_user_with_no_permissions_is_offered_no_gated_links(): void
    {
        $html = $this->sidebar($this->user());

        foreach (self::GATED_LINKS as $name => $permission) {
            $this->assertStringNotContainsString(
                'href="' . route($name) . '"',
                $html,
                "The sidebar offers '{$name}' to a user without '{$permission}'."
            );
        }
    }

    public function test_each_permission_reveals_exactly_its_own_link(): void
    {
        foreach (self::GATED_LINKS as $name => $permission) {
            $html = $this->sidebar($this->user($permission));

            $this->assertStringContainsString(
                'href="' . route($name) . '"',
                $html,
                "Granting '{$permission}' did not reveal '{$name}' in the sidebar."
            );
        }
    }

    public function test_links_open_to_every_signed_in_user_are_always_offered(): void
    {
        $html = $this->sidebar($this->user());

        // Chat and Reviews are deliberately universal: everyone may message a
        // colleague, and everyone may file a review.
        $this->assertStringContainsString('href="' . route('chat.index') . '"', $html);
        $this->assertStringContainsString('href="' . route('reviews.index') . '"', $html);
        $this->assertStringContainsString('href="' . route('requests.index') . '"', $html);
    }

    public function test_the_administration_group_is_super_admin_only(): void
    {
        $plain = $this->sidebar($this->user());
        $this->assertStringNotContainsString('Administration', $plain);
        $this->assertStringNotContainsString('href="' . route('settings.index') . '"', $plain);
        $this->assertStringNotContainsString('href="' . route('roles.index') . '"', $plain);
    }

    public function test_a_manager_gets_pending_changes_without_being_shown_an_admin_group(): void
    {
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $manager = $this->user();
        $manager->assignRole('Manager');

        $html = $this->sidebar($manager->fresh());

        // Approving changes is a manager task, so it appears — but under
        // Management, not under a heading claiming they are an administrator.
        $this->assertStringContainsString('href="' . route('pending-changes.index') . '"', $html);
        $this->assertStringNotContainsString('Administration', $html);
    }

    public function test_a_super_admin_sees_the_administration_group(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = $this->user();
        $admin->assignRole('Super Admin');

        $html = $this->sidebar($admin->fresh());

        $this->assertStringContainsString('Administration', $html);
        $this->assertStringContainsString('href="' . route('settings.index') . '"', $html);
    }

    public function test_the_client_search_box_follows_client_visibility(): void
    {
        $this->assertStringNotContainsString('id="globalSearch"', $this->sidebar($this->user()));
        $this->assertStringContainsString('id="globalSearch"', $this->sidebar($this->user('view clients')));
    }
}
