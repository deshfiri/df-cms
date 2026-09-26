<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * There can be several workflows in flight at once. Marking one as the "lead"
 * workflow is what the admin/manager dashboard's pipeline widgets read from —
 * without one, they have nothing to show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->boolean('is_lead')->default(false)->after('client_visible');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('is_lead');
        });
    }
};
