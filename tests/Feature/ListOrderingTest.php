<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Lists open newest first; clients by DFID, highest first.
 */
class ListOrderingTest extends TestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    public function test_clients_sort_by_dfid_descending_in_natural_order(): void
    {
        foreach (['view clients', 'manage clients', 'delete clients', 'view payments', 'manage payments'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $user = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view clients');
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        // Inserted out of order, and "DF100" must land above "DF99" (a plain
        // string sort would put it below).
        foreach (['DF99', 'DF100', 'DF7', 'DF101'] as $dfid) {
            Client::create(['dfid_number' => $dfid, 'client_name' => "Client {$dfid}", 'brand_name' => 'B', 'category_id' => $category->id]);
        }

        $rows = $this->actingAs($user)->getJson(route('clients.index', [
            'draw' => 1, 'start' => 0, 'length' => 25,
            'order'   => [['column' => 2, 'dir' => 'desc']],
            'columns' => [
                ['data' => '', 'orderable' => 'false', 'searchable' => 'false'],
                ['data' => 'DT_RowIndex', 'orderable' => 'false', 'searchable' => 'false'],
                ['data' => 'dfid', 'name' => 'dfid_number', 'orderable' => 'true', 'searchable' => 'false'],
            ],
        ]), self::AJAX)->assertOk();
        $this->assertNull($rows->json('error'), (string) $rows->json('error'));
        $rows = $rows->json('data');

        $this->assertSame(['DF101', 'DF100', 'DF99', 'DF7'], array_map(fn ($r) => trim(strip_tags($r['dfid'])), $rows), json_encode(array_map(fn ($r) => $r['dfid'], $rows)));
    }

    public function test_the_clients_page_opens_on_dfid_descending(): void
    {
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => 'web']);
        $user = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view clients');

        $this->actingAs($user)->get(route('clients.index'))
            ->assertOk()
            ->assertSee("order: [[2, 'desc']]", false);
    }
}
