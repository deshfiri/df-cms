<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            // When the callee last looked at their call log. Null on a call they
            // never took is what makes a missed call *unseen* rather than merely
            // recorded — without it the log is history nobody is told about.
            $table->timestamp('callee_seen_at')->nullable()->after('failure_reason');

            // Drives the unseen-missed badge on every chat page load.
            $table->index(['callee_id', 'callee_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['callee_id', 'callee_seen_at']);
            $table->dropColumn('callee_seen_at');
        });
    }
};
