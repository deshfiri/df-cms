<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_portal_user_id')
                ->constrained('client_portal_users', indexName: 'cpnp_portal_user_id_foreign')
                ->cascadeOnDelete();
            $table->unique('client_portal_user_id', 'cpnp_portal_user_id_unique');

            $table->boolean('email_enabled')->default(true);
            $table->boolean('notify_journey_updates')->default(true);
            $table->boolean('notify_project_updates')->default(true);
            $table->boolean('notify_action_requests')->default(true);
            $table->boolean('notify_approval_requests')->default(true);
            $table->boolean('notify_documents')->default(true);
            $table->boolean('notify_payments')->default(true);
            $table->boolean('notify_support')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_notification_preferences');
    }
};
