<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Responding used to write straight onto the request's own status/reviewed_by
 * columns, so the first recipient to answer decided the outcome for everyone
 * else named on it — a request sent to three people was settled by whichever
 * one clicked first, and the other two lost the ability to respond at all.
 *
 * Each recipient now carries their own status here. The request's own status
 * stays a derived summary: Rejected the moment any one recipient rejects,
 * Approved only once every recipient has, Pending otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_request_recipients', function (Blueprint $table) {
            $table->string('status')->default('Pending')->after('user_id');
            $table->text('note')->nullable()->after('status');
            $table->timestamp('responded_at')->nullable()->after('note');
        });

        // Backfill: under the old model only the recorded reviewer ever
        // actually responded — everyone else named on the request never got
        // the chance. Give that one recipient (if they're still named on it)
        // the request's own outcome; leave the rest Pending, matching what
        // really happened.
        DB::table('employee_requests')
            ->whereIn('status', ['Approved', 'Rejected'])
            ->whereNotNull('reviewed_by')
            ->orderBy('id')
            ->each(function ($request) {
                DB::table('employee_request_recipients')
                    ->where('employee_request_id', $request->id)
                    ->where('user_id', $request->reviewed_by)
                    ->update([
                        'status'       => $request->status,
                        'note'         => $request->response_note,
                        'responded_at' => $request->reviewed_at,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('employee_request_recipients', function (Blueprint $table) {
            $table->dropColumn(['status', 'note', 'responded_at']);
        });
    }
};
