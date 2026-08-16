<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
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

    /** No ClientFactory in this project — build one the way the other tests do. */
    private function makeClient(): Client
    {
        $category = Category::create([
            'name'   => 'Test Category',
            'slug'   => 'test-category-' . uniqid(),
            'status' => true,
        ]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'Test Client',
            'brand_name'  => 'Test Brand',
            'category_id' => $category->id,
        ]);
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

    public function test_global_search_will_not_return_clients_without_permission(): void
    {
        // The topbar search box sits on every page for every signed-in user and
        // returns client names, brands and DFID numbers.
        $this->actingAs($this->user())
            ->getJson(route('search.global', ['q' => 'acme']))
            ->assertForbidden();
    }

    public function test_global_search_works_for_a_permitted_user(): void
    {
        // Two characters on purpose: 3+ takes the MySQL FULLTEXT branch
        // (MATCH ... AGAINST), which the sqlite test database cannot parse.
        // The short-query LIKE fallback exercises the same authorization path.
        $this->actingAs($this->user('view clients'))
            ->getJson(route('search.global', ['q' => 'ac']))
            ->assertOk();
    }

    public function test_client_notes_cannot_be_read_without_permission(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user())
            ->getJson(route('clients.notes.index', $client))
            ->assertForbidden();
    }

    public function test_client_product_updates_cannot_be_read_without_permission(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user())
            ->getJson(route('clients.products.index', $client))
            ->assertForbidden();
    }

    public function test_a_permitted_user_can_still_read_notes_and_product_updates(): void
    {
        $client = $this->makeClient();
        $user   = $this->user('view clients');

        $this->actingAs($user)->getJson(route('clients.notes.index', $client))->assertOk();
        $this->actingAs($user)->getJson(route('clients.products.index', $client))->assertOk();
    }
}
