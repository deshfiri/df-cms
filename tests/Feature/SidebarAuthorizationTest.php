<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Pages that were reachable by any authenticated user regardless of role.
 * The sidebar hid the links for some people, but the routes themselves were
 * never gated — so a direct URL (or the DataTables ajax behind it) exposed
 * client data to roles holding no client permission at all.
 */
class SidebarAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view clients', 'manage clients', 'submit-stage'] as $permission) {
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

    public function test_the_client_list_is_closed_to_users_without_client_permission(): void
    {
        $this->actingAs($this->user())
            ->get(route('clients.index'))
            ->assertForbidden();
    }

    public function test_the_client_list_datatable_ajax_is_closed_too(): void
    {
        // The page and its data feed are separate requests — gating only the
        // page would still leak the whole list over ajax.
        $this->actingAs($this->user())
            ->getJson(route('clients.index'))
            ->assertForbidden();
    }

    public function test_a_user_with_view_clients_can_open_the_client_list(): void
    {
        $this->actingAs($this->user('view clients'))
            ->get(route('clients.index'))
            ->assertOk();
    }

    public function test_the_all_meetings_list_is_closed_to_users_without_client_permission(): void
    {
        $this->actingAs($this->user())
            ->get(route('meetings.all'))
            ->assertForbidden();
    }

    public function test_a_user_with_view_clients_can_open_the_all_meetings_list(): void
    {
        $this->actingAs($this->user('view clients'))
            ->get(route('meetings.all'))
            ->assertOk();
    }

    public function test_manage_clients_alone_also_grants_access(): void
    {
        // ClientPolicy::viewAny accepts either permission; the meetings gate
        // must agree with it or the two pages disagree about who may look.
        $user = $this->user('manage clients');

        $this->actingAs($user)->get(route('clients.index'))->assertOk();
        $this->actingAs($user)->get(route('meetings.all'))->assertOk();
    }

    public function test_stage_workers_are_still_redirected_away_from_the_client_list(): void
    {
        // Department workers hold 'view clients' but work only from My Work,
        // so they are redirected rather than forbidden.
        $this->actingAs($this->user('view clients', 'submit-stage'))
            ->get(route('clients.index'))
            ->assertRedirect(route('dashboard'));
    }
}
