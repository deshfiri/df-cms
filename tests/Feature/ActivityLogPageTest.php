<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The admin-wide Activity Log page: every module's own ActivityLog rows in
 * one filterable place, gated by 'view activity log' — distinct from the
 * older per-client activity tab (ClientController::activity()), which only
 * ever shows rows tied to that one client and never the many rows recorded
 * with a null client_id.
 */
class ActivityLogPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view activity log', 'guard_name' => 'web']);
    }

    private function viewer(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('view activity log')->fresh();
    }

    private function log(array $overrides = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'user_id' => null, 'client_id' => null, 'module' => 'File Manager', 'action' => 'File Uploaded',
            'old_value' => null, 'new_value' => null, 'ip_address' => '127.0.0.1', 'browser' => 'Test Agent',
        ], $overrides));
    }

    public function test_someone_without_the_permission_is_refused(): void
    {
        $someone = User::factory()->create(['is_active' => true]);

        $this->actingAs($someone)->get(route('activity-log.index'))->assertForbidden();
    }

    public function test_someone_with_the_permission_can_open_it(): void
    {
        $viewer = $this->viewer();
        $this->log(['module' => 'User', 'action' => 'Updated']);

        $this->actingAs($viewer)->get(route('activity-log.index'))
            ->assertOk()
            ->assertSee('Activity Log');
    }

    public function test_super_admin_sees_it_without_the_explicit_permission(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = tap(User::factory()->create(['is_active' => true]))->assignRole('Super Admin')->fresh();

        $this->actingAs($admin)->get(route('activity-log.index'))->assertOk();
    }

    public function test_it_lists_entries_that_have_no_client_at_all_unlike_the_old_per_client_tab(): void
    {
        $viewer = $this->viewer();
        $this->log(['module' => 'Performance', 'action' => 'KPI Weights Saved', 'client_id' => null]);

        $rows = $this->actingAs($viewer)
            ->getJson(route('activity-log.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data');

        $this->assertTrue(collect($rows)->contains(fn ($r) => str_contains($r['action'], 'KPI Weights Saved')));
    }

    public function test_it_can_be_filtered_by_module(): void
    {
        $viewer = $this->viewer();
        $this->log(['module' => 'Task', 'action' => 'Reassigned']);
        $this->log(['module' => 'Payment', 'action' => 'Created']);

        $rows = $this->actingAs($viewer)
            ->getJson(route('activity-log.index', ['module' => 'Task']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data');

        $this->assertTrue(collect($rows)->every(fn ($r) => trim(strip_tags($r['module_badge'])) === 'Task'));
    }

    public function test_it_can_be_filtered_by_user(): void
    {
        $viewer = $this->viewer();
        $alice = tap(User::factory()->create(['is_active' => true]))->update(['name' => 'Alice']);
        $bob = tap(User::factory()->create(['is_active' => true]))->update(['name' => 'Bob']);
        $this->log(['user_id' => $alice->id, 'module' => 'Task', 'action' => 'Reassigned']);
        $this->log(['user_id' => $bob->id, 'module' => 'Task', 'action' => 'Reassigned']);

        $rows = $this->actingAs($viewer)
            ->getJson(route('activity-log.index', ['user_id' => $alice->id]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['user']);
    }

    public function test_it_can_be_filtered_by_action_substring(): void
    {
        $viewer = $this->viewer();
        $this->log(['module' => 'Client', 'action' => 'Deleted']);
        $this->log(['module' => 'Client', 'action' => 'Updated']);

        $rows = $this->actingAs($viewer)
            ->getJson(route('activity-log.index', ['action' => 'Delet']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Deleted', $rows[0]['action']);
    }

    public function test_it_can_be_filtered_by_date_range(): void
    {
        $viewer = $this->viewer();
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00'));
        $this->log(['module' => 'Client', 'action' => 'Old One']);
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        $this->log(['module' => 'Client', 'action' => 'New One']);

        $rows = $this->actingAs($viewer)
            ->getJson(route('activity-log.index', ['date_from' => '2026-09-15']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('New One', $rows[0]['action']);
    }

    public function test_the_details_endpoint_returns_the_before_and_after_values(): void
    {
        $viewer = $this->viewer();
        $log = $this->log([
            'module' => 'Client', 'action' => 'Status Changed',
            'old_value' => 'Active', 'new_value' => 'On Hold',
        ]);

        $this->actingAs($viewer)->getJson(route('activity-log.show', $log))
            ->assertOk()
            ->assertJson(['module' => 'Client', 'action' => 'Status Changed', 'old_value' => 'Active', 'new_value' => 'On Hold']);
    }

    public function test_the_details_endpoint_is_also_permission_gated(): void
    {
        $someone = User::factory()->create(['is_active' => true]);
        $log = $this->log();

        $this->actingAs($someone)->getJson(route('activity-log.show', $log))->assertForbidden();
    }

    public function test_the_sidebar_link_only_appears_for_someone_with_the_permission(): void
    {
        $viewer = $this->viewer();
        $someone = User::factory()->create(['is_active' => true]);

        $this->actingAs($viewer)->get(route('my-work'))->assertSee(route('activity-log.index'), false);
        $this->actingAs($someone)->get(route('my-work'))->assertDontSee(route('activity-log.index'), false);
    }
}
