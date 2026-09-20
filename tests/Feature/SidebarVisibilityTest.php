<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The sidebar must offer exactly what the policies allow.
 *
 * The bug this exists for: several menu links were gated on the bare permission
 * `view x`, while the matching policy admitted `view x` OR `manage x`. Granting
 * a role only the *stronger* permission therefore hid the menu, even though the
 * page behind it would have let them straight in — an invisible feature rather
 * than a forbidden one, which is far harder to diagnose.
 */
class SidebarVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'view clients', 'manage clients',
            'view tasks', 'manage tasks',
            'view ads', 'manage ads',
            'submit-stage', 'export clients', 'view file-manager',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    // ── Clients ──────────────────────────────────────────────────────────

    public function test_manage_clients_alone_shows_the_clients_menu(): void
    {
        $this->actingAs($this->userWith('manage clients'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('clients.index'), false);
    }

    public function test_view_clients_alone_shows_the_clients_menu(): void
    {
        $this->actingAs($this->userWith('view clients'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('clients.index'), false);
    }

    public function test_neither_permission_hides_the_clients_menu(): void
    {
        $this->actingAs($this->userWith())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('clients.index'), false);
    }

    /** The menu and the page must agree — visible implies reachable. */
    public function test_manage_clients_alone_can_open_the_clients_page(): void
    {
        $this->actingAs($this->userWith('manage clients'))
            ->get(route('clients.index'))
            ->assertOk();
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    public function test_manage_tasks_alone_shows_the_tasks_menu(): void
    {
        $this->actingAs($this->userWith('manage tasks'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('tasks.index'), false);
    }

    public function test_manage_tasks_alone_can_open_the_tasks_page(): void
    {
        $this->actingAs($this->userWith('manage tasks'))
            ->get(route('tasks.index'))
            ->assertOk();
    }

    public function test_neither_task_permission_hides_the_tasks_menu(): void
    {
        $this->actingAs($this->userWith())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('tasks.index'), false);
    }

    // ── Ads ──────────────────────────────────────────────────────────────

    public function test_manage_ads_alone_shows_the_ads_menu(): void
    {
        $this->actingAs($this->userWith('manage ads'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('ads.index'), false);
    }

    public function test_neither_ads_permission_hides_the_ads_menu(): void
    {
        $this->actingAs($this->userWith())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('ads.index'), false);
    }

    // ── Department workers ───────────────────────────────────────────────

    /**
     * Someone who works a workflow stage used to be given a cut-down menu that
     * dropped Clients, Ads, Requests, My Work and the whole Operations section —
     * permissions they held and pages they could open.
     */
    public function test_a_stage_worker_sees_every_menu_their_permissions_allow(): void
    {
        $worker = $this->userWith('submit-stage', 'view clients', 'view ads', 'export clients', 'view file-manager');

        $this->actingAs($worker)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('clients.index'), false)
            ->assertSee(route('ads.index'), false)
            ->assertSee(route('file-manager.index'), false)
            ->assertSee(route('requests.index'), false)
            ->assertSee(route('my-work'), false)
            ->assertSee('Export Data');
    }

    public function test_a_stage_worker_is_still_offered_nothing_they_lack(): void
    {
        $this->actingAs($this->userWith('submit-stage'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('clients.index'), false)
            ->assertDontSee(route('ads.index'), false)
            ->assertDontSee(route('file-manager.index'), false);
    }
}
