<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_meetings', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('google_event_id', 255)->nullable()->after('meeting_link');
            $table->string('google_meet_url', 500)->nullable()->after('google_event_id');
            $table->timestamp('completed_at')->nullable()->after('notes');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reminder_24h_sent_at')->nullable()->after('completed_by');
            $table->timestamp('reminder_1h_sent_at')->nullable()->after('reminder_24h_sent_at');
            $table->timestamp('reminder_15m_sent_at')->nullable()->after('reminder_1h_sent_at');
        });

        // Convert the fixed MySQL ENUM to a plain string so new statuses (Pending, No Show)
        // don't require a schema migration every time — matches the convention already used
        // for client_stage_progress.status and tasks.status elsewhere in this app.
        // Sqlite (test suite) has no MODIFY syntax and already stores enum columns as
        // loosely-typed text, so there's nothing to convert there.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE client_meetings MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Scheduled'");
        }

        // Normalize existing lowercase values to the new Title Case status set.
        DB::table('client_meetings')->where('status', 'scheduled')->update(['status' => 'Scheduled']);
        DB::table('client_meetings')->where('status', 'completed')->update(['status' => 'Completed']);
        DB::table('client_meetings')->where('status', 'cancelled')->update(['status' => 'Cancelled']);
        DB::table('client_meetings')->where('status', 'rescheduled')->update(['status' => 'Rescheduled']);
    }

    public function down(): void
    {
        DB::table('client_meetings')->where('status', 'Scheduled')->update(['status' => 'scheduled']);
        DB::table('client_meetings')->where('status', 'Completed')->update(['status' => 'completed']);
        DB::table('client_meetings')->where('status', 'Cancelled')->update(['status' => 'cancelled']);
        DB::table('client_meetings')->where('status', 'Rescheduled')->update(['status' => 'rescheduled']);
        DB::table('client_meetings')->whereIn('status', ['Pending', 'No Show'])->update(['status' => 'scheduled']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE client_meetings MODIFY status ENUM('scheduled','completed','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled'");
        }

        Schema::table('client_meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['google_event_id', 'google_meet_url', 'completed_at', 'reminder_24h_sent_at', 'reminder_1h_sent_at', 'reminder_15m_sent_at']);
        });
    }
};
