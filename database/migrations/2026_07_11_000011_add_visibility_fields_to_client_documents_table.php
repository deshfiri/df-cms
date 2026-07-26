<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->boolean('is_client_visible')->default(false)->after('tags');
            $table->timestamp('visible_to_client_at')->nullable()->after('is_client_visible');
            $table->boolean('is_client_submitted')->default(false)->after('visible_to_client_at');
            $table->foreignId('submitted_by_portal_user_id')->nullable()
                ->after('is_client_submitted')->constrained('client_portal_users')->nullOnDelete();
            $table->string('client_review_status')->nullable()->after('submitted_by_portal_user_id')
                ->comment('Pending Review,Approved,Rejected');
            $table->foreignId('reviewed_by')->nullable()->after('client_review_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');
            $table->foreignId('stage_id')->nullable()->after('review_note')
                ->constrained('workflow_stages')->restrictOnDelete();
        });

        Schema::table('client_documents', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_portal_user_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('stage_id');
            $table->dropColumn([
                'is_client_visible', 'visible_to_client_at', 'is_client_submitted',
                'client_review_status', 'reviewed_at', 'review_note',
            ]);
            $table->foreignId('uploaded_by')->nullable(false)->change();
        });
    }
};
