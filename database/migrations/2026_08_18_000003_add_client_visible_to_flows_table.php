<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a flow's work is shown to the client in their portal.
 *
 * The retired pipeline marked visibility per stage, because every client ran
 * the same 19 stages and some were internal. A flow is the meaningful unit
 * here: "Website Build" is the client's business, an internal QA loop is not.
 *
 * Defaults to visible — a flow attached to a client is client work unless an
 * admin says otherwise — but internal flows can be hidden without detaching
 * them from the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->boolean('client_visible')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('client_visible');
        });
    }
};
