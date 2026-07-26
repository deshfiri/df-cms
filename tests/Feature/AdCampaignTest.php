<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdCampaignAssignment;
use App\Models\AdCampaignDailyReport;
use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view ads', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage ads', 'guard_name' => 'web']);
    }

    private function makeClient(): Client
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'Test Client',
            'brand_name'  => 'Test Brand',
            'category_id' => $category->id,
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        if (in_array($role, ['Marketing', 'Manager', 'Super Admin'], true)) {
            $user->givePermissionTo(['view ads', 'manage ads']);
        }

        return $user;
    }

    public function test_marketing_user_can_create_a_campaign_for_a_client(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();

        $response = $this->actingAs($marketing)->postJson(route('clients.ads.store', $client), [
            'name'   => 'Eid Collection Launch',
            'status' => 'Active',
            'budget' => 50000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('ad_campaigns', ['client_id' => $client->id, 'name' => 'Eid Collection Launch']);
    }

    public function test_sales_user_without_ads_permission_cannot_create_a_campaign(): void
    {
        $sales = $this->makeUser('Sales');
        $client = $this->makeClient();

        $response = $this->actingAs($sales)->postJson(route('clients.ads.store', $client), [
            'name'   => 'Unauthorized Campaign',
            'status' => 'Active',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('ad_campaigns', ['name' => 'Unauthorized Campaign']);
    }

    public function test_assigned_user_can_view_and_update_their_own_campaign_without_ads_permission(): void
    {
        $marketing = $this->makeUser('Marketing');
        $sales = $this->makeUser('Sales'); // no ads permission at all
        $client = $this->makeClient();

        $campaign = AdCampaign::create([
            'client_id'   => $client->id,
            'assigned_to' => $sales->id,
            'name'        => 'Assigned Campaign',
            'status'      => 'Active',
            'created_by'  => $marketing->id,
        ]);

        $this->assertTrue($sales->can('view', $campaign));
        $this->assertTrue($sales->can('update', $campaign));

        $response = $this->actingAs($sales)->putJson(route('clients.ads.update', [$client, $campaign]), [
            'name'   => 'Assigned Campaign Updated',
            'status' => 'Paused',
        ]);

        $response->assertOk();
        $this->assertSame('Paused', $campaign->fresh()->status);
    }

    public function test_unassigned_non_privileged_user_cannot_view_or_update_someone_elses_campaign(): void
    {
        $marketing = $this->makeUser('Marketing');
        $sales = $this->makeUser('Sales');
        $otherSales = $this->makeUser('Sales');
        $client = $this->makeClient();

        $campaign = AdCampaign::create([
            'client_id'   => $client->id,
            'assigned_to' => $sales->id,
            'name'        => 'Someone Elses Campaign',
            'status'      => 'Active',
            'created_by'  => $marketing->id,
        ]);

        $this->assertFalse($otherSales->can('view', $campaign));
        $this->assertFalse($otherSales->can('update', $campaign));

        $response = $this->actingAs($otherSales)->get(route('clients.ads.show', [$client, $campaign]));
        $response->assertForbidden();
    }

    public function test_only_manage_ads_holder_can_assign_a_campaign(): void
    {
        $marketing = $this->makeUser('Marketing');
        $sales = $this->makeUser('Sales');
        $newAssignee = $this->makeUser('Sales');
        $client = $this->makeClient();

        $campaign = AdCampaign::create([
            'client_id'  => $client->id,
            'name'       => 'Reassign Me',
            'status'     => 'Active',
            'created_by' => $marketing->id,
        ]);

        $blocked = $this->actingAs($sales)->postJson(route('clients.ads.assign', [$client, $campaign]), [
            'new_assignee_id' => $newAssignee->id,
        ]);
        $blocked->assertForbidden();

        $allowed = $this->actingAs($marketing)->postJson(route('clients.ads.assign', [$client, $campaign]), [
            'new_assignee_id' => $newAssignee->id,
            'note'            => 'Please handle this',
        ]);
        $allowed->assertOk();

        $this->assertSame($newAssignee->id, $campaign->fresh()->assigned_to);
        $this->assertDatabaseHas('ad_campaign_assignments', [
            'ad_campaign_id'       => $campaign->id,
            'previous_assignee_id' => null,
            'new_assignee_id'      => $newAssignee->id,
            'assigned_by'          => $marketing->id,
            'note'                 => 'Please handle this',
        ]);
    }

    public function test_reassignment_preserves_prior_assignment_history_rows(): void
    {
        $marketing = $this->makeUser('Marketing');
        $userA = $this->makeUser('Sales');
        $userB = $this->makeUser('Sales');
        $client = $this->makeClient();

        $campaign = AdCampaign::create([
            'client_id'  => $client->id,
            'name'       => 'Multi-assign Campaign',
            'status'     => 'Active',
            'created_by' => $marketing->id,
        ]);

        $this->actingAs($marketing)->postJson(route('clients.ads.assign', [$client, $campaign]), [
            'new_assignee_id' => $userA->id,
        ])->assertOk();

        $this->actingAs($marketing)->postJson(route('clients.ads.assign', [$client, $campaign]), [
            'new_assignee_id' => $userB->id,
        ])->assertOk();

        $this->assertSame(2, AdCampaignAssignment::where('ad_campaign_id', $campaign->id)->count());
        $this->assertSame($userB->id, $campaign->fresh()->assigned_to);
    }

    public function test_daily_report_upsert_updates_same_date_row_instead_of_duplicating(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();

        $campaign = AdCampaign::create([
            'client_id'  => $client->id,
            'name'       => 'Report Test Campaign',
            'status'     => 'Active',
            'created_by' => $marketing->id,
        ]);

        $payload = [
            'report_date' => '2026-07-10',
            'spend'       => 1000,
            'sales'       => 3000,
            'leads'       => 20,
            'orders'      => 10,
        ];

        $this->actingAs($marketing)->postJson(route('clients.ads.reports.store', [$client, $campaign]), $payload)->assertOk();
        $this->assertSame(1, AdCampaignDailyReport::where('ad_campaign_id', $campaign->id)->count());

        // Resubmitting the same date updates the existing row, not a new one.
        $this->actingAs($marketing)->postJson(route('clients.ads.reports.store', [$client, $campaign]), array_merge($payload, ['spend' => 1500]))->assertOk();

        $this->assertSame(1, AdCampaignDailyReport::where('ad_campaign_id', $campaign->id)->count());
        $report = AdCampaignDailyReport::where('ad_campaign_id', $campaign->id)->first();
        $this->assertSame('1500.00', $report->spend);
    }

    public function test_campaign_rollup_metrics_compute_correctly_across_multiple_daily_reports(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();

        $campaign = AdCampaign::create([
            'client_id'  => $client->id,
            'name'       => 'Rollup Test',
            'status'     => 'Active',
            'budget'     => 5000,
            'created_by' => $marketing->id,
        ]);

        AdCampaignDailyReport::create(['ad_campaign_id' => $campaign->id, 'report_date' => '2026-07-01', 'spend' => 500, 'sales' => 1500, 'leads' => 10, 'orders' => 5]);
        AdCampaignDailyReport::create(['ad_campaign_id' => $campaign->id, 'report_date' => '2026-07-02', 'spend' => 500, 'sales' => 1500, 'leads' => 10, 'orders' => 5]);

        $campaign->refresh();

        $this->assertEquals(1000.0, $campaign->total_spend);
        $this->assertEquals(3000.0, $campaign->total_sales);
        $this->assertEquals(20, $campaign->total_leads);
        $this->assertEquals(10, $campaign->total_orders);
        $this->assertEquals(3.0, $campaign->roas);
        $this->assertEquals(50.0, $campaign->cpl);
        $this->assertEquals(100.0, $campaign->cpa);
        $this->assertEquals(4000.0, $campaign->budget_remaining);
    }

    public function test_only_super_admin_or_manager_can_delete_a_campaign(): void
    {
        $marketing = $this->makeUser('Marketing'); // has manage ads, but not Super Admin/Manager role
        $manager = $this->makeUser('Manager');
        $client = $this->makeClient();

        $campaign = AdCampaign::create([
            'client_id'  => $client->id,
            'name'       => 'Delete Test',
            'status'     => 'Active',
            'created_by' => $marketing->id,
        ]);

        $blocked = $this->actingAs($marketing)->deleteJson(route('clients.ads.destroy', [$client, $campaign]));
        $blocked->assertForbidden();

        $allowed = $this->actingAs($manager)->deleteJson(route('clients.ads.destroy', [$client, $campaign]));
        $allowed->assertOk();

        $this->assertSoftDeleted('ad_campaigns', ['id' => $campaign->id]);
    }
}
