<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientCustomerReasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage clients', 'guard_name' => 'web']);
        // The list's Actions column asks ClientPolicy::delete, which needs the
        // permission to exist — as it does in every seeded install.
        Permission::firstOrCreate(['name' => 'delete clients', 'guard_name' => 'web']);
    }

    private function makeClient(?int $assignedTo = null, array $extra = []): Client
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category-' . uniqid(), 'status' => true]);

        return Client::create(array_merge([
            'dfid_number'   => 'DF' . uniqid(),
            'client_name'   => 'Test Client',
            'brand_name'    => 'Test Brand',
            'category_id'   => $category->id,
            'assigned_to'   => $assignedTo,
            'client_status' => 'Running',
        ], $extra));
    }

    private function makeUser(string $role, bool $canManage = true): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        $user->givePermissionTo('view clients');
        if ($canManage) {
            $user->givePermissionTo('manage clients');
        }

        return $user;
    }

    private function reasonLogs(Client $client)
    {
        return ActivityLog::where('client_id', $client->id)->where('action', 'Customer Reason Changed')->get();
    }

    private function editFormPayload(Client $client, array $overrides = []): array
    {
        return array_merge([
            'client_name'   => $client->client_name,
            'brand_name'    => $client->brand_name,
            'category_id'   => $client->category_id,
            'client_status' => $client->client_status,
            'assigned_to'   => $client->assigned_to,
        ], $overrides);
    }

    public function test_inline_edit_saves_the_reason_and_logs_the_change(): void
    {
        $sales  = $this->makeUser('Sales');
        $client = $this->makeClient($sales->id);

        $this->actingAs($sales)
            ->postJson(route('clients.customer-reason', $client), ['customer_reason' => '  Budget too low  '])
            ->assertOk()
            ->assertJson(['success' => true, 'customer_reason' => 'Budget too low']);

        $this->assertSame('Budget too low', $client->fresh()->customer_reason);

        $logs = $this->reasonLogs($client);
        $this->assertCount(1, $logs);
        $this->assertNull($logs[0]->old_value);
        $this->assertSame('Budget too low', $logs[0]->new_value);
        $this->assertSame($sales->id, $logs[0]->user_id);
        $this->assertNotNull($logs[0]->created_at);
    }

    public function test_saving_an_unchanged_reason_writes_no_history(): void
    {
        $sales  = $this->makeUser('Sales');
        $client = $this->makeClient($sales->id, ['customer_reason' => 'Budget too low']);

        $this->actingAs($sales)
            ->postJson(route('clients.customer-reason', $client), ['customer_reason' => 'Budget too low'])
            ->assertOk();

        $this->assertCount(0, $this->reasonLogs($client));
    }

    public function test_clearing_the_reason_stores_null_and_logs_it(): void
    {
        $sales  = $this->makeUser('Sales');
        $client = $this->makeClient($sales->id, ['customer_reason' => 'Budget too low']);

        $this->actingAs($sales)
            ->postJson(route('clients.customer-reason', $client), ['customer_reason' => ''])
            ->assertOk();

        $this->assertNull($client->fresh()->customer_reason);
        $logs = $this->reasonLogs($client);
        $this->assertCount(1, $logs);
        $this->assertSame('Budget too low', $logs[0]->old_value);
        $this->assertNull($logs[0]->new_value);
    }

    public function test_inline_edit_rejects_an_overlong_reason(): void
    {
        $sales  = $this->makeUser('Sales');
        $client = $this->makeClient($sales->id);

        $this->actingAs($sales)
            ->postJson(route('clients.customer-reason', $client), ['customer_reason' => str_repeat('a', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_reason');

        $this->assertNull($client->fresh()->customer_reason);
    }

    public function test_inline_edit_is_refused_without_update_rights(): void
    {
        $owner    = $this->makeUser('Sales');
        $other    = $this->makeUser('Sales');
        $viewOnly = $this->makeUser('Viewer', canManage: false);
        $client   = $this->makeClient($owner->id);

        $this->actingAs($other)
            ->postJson(route('clients.customer-reason', $client), ['customer_reason' => 'x'])
            ->assertForbidden();
        $this->actingAs($viewOnly)
            ->postJson(route('clients.customer-reason', $client), ['customer_reason' => 'x'])
            ->assertForbidden();

        $this->assertNull($client->fresh()->customer_reason);
        $this->assertCount(0, $this->reasonLogs($client));
    }

    public function test_edit_form_saves_the_reason_and_shows_the_inline_value(): void
    {
        $admin  = $this->makeUser('Super Admin');
        $client = $this->makeClient(null);

        // Saved inline, shown in the edit form.
        $this->actingAs($admin)
            ->postJson(route('clients.customer-reason', $client), ['customer_reason' => 'Moved to competitor']);
        $this->actingAs($admin)->get(route('clients.edit', $client))
            ->assertOk()
            ->assertSee('Moved to competitor');

        // Saved from the edit form, shown in the list.
        $this->actingAs($admin)
            ->put(route('clients.update', $client), $this->editFormPayload($client, ['customer_reason' => 'Paused for Eid']))
            ->assertRedirect(route('clients.show', $client));

        $this->assertSame('Paused for Eid', $client->fresh()->customer_reason);
        $this->assertCount(2, $this->reasonLogs($client));

        $this->actingAs($admin)
            ->getJson(route('clients.index', ['draw' => 1, 'start' => 0, 'length' => 25]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Paused for Eid');
    }

    public function test_edit_form_with_an_unchanged_reason_writes_no_reason_history(): void
    {
        $admin  = $this->makeUser('Super Admin');
        $client = $this->makeClient(null, ['customer_reason' => 'Paused for Eid']);

        $this->actingAs($admin)
            ->put(route('clients.update', $client), $this->editFormPayload($client, ['customer_reason' => 'Paused for Eid', 'client_name' => 'Renamed']))
            ->assertRedirect();

        $this->assertSame('Renamed', $client->fresh()->client_name);
        $this->assertCount(0, $this->reasonLogs($client));
    }

    public function test_edit_form_rejects_an_overlong_reason(): void
    {
        $admin  = $this->makeUser('Super Admin');
        $client = $this->makeClient(null);

        $this->actingAs($admin)
            ->put(route('clients.update', $client), $this->editFormPayload($client, ['customer_reason' => str_repeat('a', 1001)]))
            ->assertSessionHasErrors('customer_reason');

        $this->assertNull($client->fresh()->customer_reason);
    }

    public function test_an_edit_without_the_field_leaves_the_reason_alone(): void
    {
        $admin  = $this->makeUser('Super Admin');
        $client = $this->makeClient(null, ['customer_reason' => 'Keep me']);

        $payload = $this->editFormPayload($client, ['client_name' => 'Renamed']);
        $this->actingAs($admin)->put(route('clients.update', $client), $payload)->assertRedirect();

        $this->assertSame('Keep me', $client->fresh()->customer_reason);
    }

    public function test_create_form_saves_the_reason_for_every_new_client(): void
    {
        $admin    = $this->makeUser('Super Admin');
        $category = Category::create(['name' => 'C', 'slug' => 'c-' . uniqid(), 'status' => true]);

        foreach (['First reason', 'Second reason', 'Third reason'] as $i => $reason) {
            $this->actingAs($admin)->get(route('clients.create'))
                ->assertOk()
                ->assertSee('name="customer_reason"', false);

            $this->actingAs($admin)->post(route('clients.store'), [
                'client_name'     => "Client {$i}",
                'brand_name'      => "Brand {$i}",
                'category_id'     => $category->id,
                'client_status'   => 'Running',
                'customer_reason' => $reason,
            ])->assertRedirect();

            $client = Client::where('client_name', "Client {$i}")->firstOrFail();
            $this->assertSame($reason, $client->customer_reason);
            $this->assertCount(1, $this->reasonLogs($client));
        }
    }

    public function test_create_form_without_a_reason_writes_no_reason_history(): void
    {
        $admin    = $this->makeUser('Super Admin');
        $category = Category::create(['name' => 'C', 'slug' => 'c-' . uniqid(), 'status' => true]);

        $this->actingAs($admin)->post(route('clients.store'), [
            'client_name' => 'No Reason', 'brand_name' => 'B', 'category_id' => $category->id,
            'client_status' => 'Running', 'customer_reason' => '',
        ])->assertRedirect();

        $client = Client::where('client_name', 'No Reason')->firstOrFail();
        $this->assertNull($client->customer_reason);
        $this->assertCount(0, $this->reasonLogs($client));
    }

    public function test_create_form_rejects_an_overlong_reason(): void
    {
        $admin    = $this->makeUser('Super Admin');
        $category = Category::create(['name' => 'C', 'slug' => 'c-' . uniqid(), 'status' => true]);

        $this->actingAs($admin)->post(route('clients.store'), [
            'client_name' => 'Too Long', 'brand_name' => 'B', 'category_id' => $category->id,
            'client_status' => 'Running', 'customer_reason' => str_repeat('a', 1001),
        ])->assertSessionHasErrors('customer_reason');

        $this->assertFalse(Client::where('client_name', 'Too Long')->exists());
    }

    private function listRow(User $user, Client $client): array
    {
        $rows = $this->actingAs($user)
            ->getJson(route('clients.index', ['draw' => 1, 'start' => 0, 'length' => 100]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonMissingPath('error')
            ->json('data');

        return collect($rows)->firstWhere('id', $client->id);
    }

    public function test_list_cell_offers_show_and_edit_when_a_reason_exists(): void
    {
        $admin  = $this->makeUser('Super Admin');
        $client = $this->makeClient(null, ['customer_reason' => 'Budget too low']);

        $cell = $this->listRow($admin, $client)['customer_reason'];

        $this->assertStringContainsString('btn-customer-reason-show', $cell);
        $this->assertStringContainsString('class="btn btn-sm px-1 py-0 btn-customer-reason"', $cell);
        $this->assertStringContainsString('data-reason="Budget too low"', $cell);
    }

    public function test_list_cell_offers_only_edit_when_reason_is_empty(): void
    {
        $admin  = $this->makeUser('Super Admin');
        $client = $this->makeClient(null);

        $cell = $this->listRow($admin, $client)['customer_reason'];

        $this->assertStringNotContainsString('btn-customer-reason-show', $cell);
        $this->assertStringContainsString('btn-customer-reason"', $cell);
    }

    public function test_list_cell_offers_show_but_not_edit_without_update_rights(): void
    {
        $owner  = $this->makeUser('Sales');
        $viewer = $this->makeUser('Viewer', canManage: false);
        // Unassigned, so the viewer can see the row but cannot edit it.
        $client = $this->makeClient(null, ['customer_reason' => 'Budget too low']);

        $cell = $this->listRow($viewer, $client)['customer_reason'];

        $this->assertStringContainsString('btn-customer-reason-show', $cell);
        $this->assertStringNotContainsString('btn-customer-reason"', $cell);
    }

    public function test_list_cell_escapes_the_reason(): void
    {
        $admin  = $this->makeUser('Super Admin');
        $client = $this->makeClient(null, ['customer_reason' => '<script>alert(1)</script> "q"']);

        $cell = $this->listRow($admin, $client)['customer_reason'];

        $this->assertStringNotContainsString('<script>', $cell);
        $this->assertStringContainsString('&lt;script&gt;', $cell);
        $this->assertStringContainsString('&quot;q&quot;', $cell);
    }

    public function test_list_column_sits_between_payment_and_actions(): void
    {
        $admin = $this->makeUser('Super Admin');

        $html = $this->actingAs($admin)->get(route('clients.index'))->assertOk()->getContent();

        $payment = strpos($html, '<th>Payment</th>');
        $reason  = strpos($html, '<th>Customer Reason</th>');
        $actions = strpos($html, '>Actions</th>', $reason ?: 0);
        $this->assertTrue($payment !== false && $reason !== false && $actions !== false);
        $this->assertTrue($payment < $reason && $reason < $actions);
    }
}
