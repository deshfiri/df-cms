<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * database/migrations/2026_09_26_090000_add_status_to_employee_request_recipients_table.php
 *
 * Under the old single-status model, only the recorded reviewer ever
 * actually answered — everyone else named on a request never got the
 * chance. This migration's backfill gives that one recipient the request's
 * recorded outcome and leaves every other named recipient Pending, since
 * that's what really happened to them.
 */
class EmployeeRequestRecipientStatusBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function runMigrationUp(): void
    {
        Schema::table('employee_request_recipients', function ($table) {
            if (Schema::hasColumn('employee_request_recipients', 'status')) {
                $table->dropColumn(['status', 'note', 'responded_at']);
            }
        });
        (require database_path('migrations/2026_09_26_090000_add_status_to_employee_request_recipients_table.php'))->up();
    }

    public function test_the_recorded_reviewer_gets_the_requests_outcome_backfilled(): void
    {
        $filer     = User::factory()->create(['is_active' => true]);
        $reviewer  = User::factory()->create(['is_active' => true]);
        $bystander = User::factory()->create(['is_active' => true]);

        $requestId = DB::table('employee_requests')->insertGetId([
            'subject' => 'Approved one', 'message' => 'x', 'requested_by' => $filer->id,
            'status' => 'Approved', 'response_note' => 'Go ahead', 'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_request_recipients')->insert([
            ['employee_request_id' => $requestId, 'user_id' => $reviewer->id],
            ['employee_request_id' => $requestId, 'user_id' => $bystander->id],
        ]);

        $this->runMigrationUp();

        $rows = DB::table('employee_request_recipients')->where('employee_request_id', $requestId)
            ->get()->keyBy('user_id');

        $this->assertSame('Approved', $rows[$reviewer->id]->status);
        $this->assertSame('Go ahead', $rows[$reviewer->id]->note);
        $this->assertNotNull($rows[$reviewer->id]->responded_at);

        $this->assertSame('Pending', $rows[$bystander->id]->status);
        $this->assertNull($rows[$bystander->id]->note);
    }

    public function test_a_still_pending_requests_recipients_are_left_pending(): void
    {
        $filer     = User::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);

        $requestId = DB::table('employee_requests')->insertGetId([
            'subject' => 'Still open', 'message' => 'x', 'requested_by' => $filer->id,
            'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_request_recipients')->insert([
            'employee_request_id' => $requestId, 'user_id' => $recipient->id,
        ]);

        $this->runMigrationUp();

        $row = DB::table('employee_request_recipients')->where('employee_request_id', $requestId)->first();
        $this->assertSame('Pending', $row->status);
    }

    /** The normal runtime path — sync(), not a raw insert — also gets the column default. */
    public function test_a_freshly_synced_recipient_defaults_to_pending_with_no_note(): void
    {
        $filer     = User::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);

        $request = \App\Models\EmployeeRequest::create([
            'subject' => 'Fresh', 'message' => 'x', 'requested_by' => $filer->id,
            'status' => 'Pending',
        ]);
        $request->recipients()->sync([$recipient->id]);

        $row = DB::table('employee_request_recipients')->where('employee_request_id', $request->id)->first();
        $this->assertSame('Pending', $row->status);
        $this->assertNull($row->note);
        $this->assertNull($row->responded_at);
    }
}
