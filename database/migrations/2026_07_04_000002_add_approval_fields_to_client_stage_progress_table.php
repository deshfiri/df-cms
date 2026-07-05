<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_stage_progress', function (Blueprint $table) {
            $table->string('status', 20)->default('Pending')->after('stage_id')
                ->comment('Pending,In Progress,Submitted,Need Revision,Approved,Rejected');
            $table->foreignId('submitted_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->text('rejection_reason')->nullable()->after('remarks');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('client_stage_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['status', 'submitted_at', 'rejection_reason']);
        });
    }
};
