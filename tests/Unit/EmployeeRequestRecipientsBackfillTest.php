<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * database/migrations/2026_09_25_100000_create_employee_request_recipients_table.php
 *
 * A migration that ships once and (outside a test) never gets a second
 * chance to be exercised, so its backfill logic — direct and role-based
 * "manage requests" holders alike, pending only — is checked directly here
 * rather than trusted from a read.
 */
class EmployeeRequestRecipientsBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function runMigrationUp(): void
    {
        // Simulate the state right before this migration first runs: no
        // pivot table yet. require (not require_once): the anonymous class
        // it returns is scoped to this one call, safe to do again from
        // another test in the same process.
        Schema::dropIfExists('employee_request_recipients');
        (require database_path('migrations/2026_09_25_100000_create_employee_request_recipients_table.php'))->up();
    }

    public function test_pending_requests_are_backfilled_to_every_current_manage_requests_holder(): void
    {
        Permission::firstOrCreate(['name' => 'manage requests', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web'])->givePermissionTo('manage requests');

        $directHolder = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage requests')->fresh();
        $roleHolder    = tap(User::factory()->create(['is_active' => true]))->assignRole('Manager')->fresh();
        $filer         = User::factory()->create(['is_active' => true]);

        $pendingId = DB::table('employee_requests')->insertGetId([
            'subject' => 'Pre-existing', 'message' => 'x', 'requested_by' => $filer->id,
            'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigrationUp();

        $recipients = DB::table('employee_request_recipients')
            ->where('employee_request_id', $pendingId)->pluck('user_id')->sort()->values()->all();

        $this->assertSame(
            collect([$directHolder->id, $roleHolder->id])->sort()->values()->all(),
            $recipients,
        );
    }

    public function test_an_already_resolved_request_gets_no_recipients_backfilled(): void
    {
        Permission::firstOrCreate(['name' => 'manage requests', 'guard_name' => 'web']);
        $holder = tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage requests')->fresh();
        $filer  = User::factory()->create(['is_active' => true]);

        $resolvedId = DB::table('employee_requests')->insertGetId([
            'subject' => 'Already handled', 'message' => 'x', 'requested_by' => $filer->id,
            'status' => 'Approved', 'reviewed_by' => $holder->id, 'reviewed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigrationUp();

        $this->assertSame(0, DB::table('employee_request_recipients')->where('employee_request_id', $resolvedId)->count());
    }

    public function test_it_is_a_harmless_no_op_when_nobody_holds_the_permission_yet(): void
    {
        // A brand-new install: the permission row may not exist at all —
        // permission() throws in that case, so the migration must guard it.
        $filer = User::factory()->create(['is_active' => true]);
        DB::table('employee_requests')->insert([
            'subject' => 'Nothing to backfill to', 'message' => 'x', 'requested_by' => $filer->id,
            'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigrationUp(); // must not throw

        $this->assertTrue(Schema::hasTable('employee_request_recipients'));
        $this->assertSame(0, DB::table('employee_request_recipients')->count());
    }
}
