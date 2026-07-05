<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Meeting completion now drives approval of this stage directly
        // (see MeetingService::complete()); it must no longer auto-approve
        // the instant it's submitted, or "Agreement Signed" would unlock
        // before the meeting was actually conducted.
        DB::table('workflow_stages')->where('code', 'meeting_scheduled')->update(['requires_approval' => true]);
    }

    public function down(): void
    {
        DB::table('workflow_stages')->where('code', 'meeting_scheduled')->update(['requires_approval' => false]);
    }
};
