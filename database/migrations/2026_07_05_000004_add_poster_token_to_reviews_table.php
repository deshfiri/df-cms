<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // A random, unguessable value returned only to the poster's own
            // browser at creation time — never linked to a user_id anywhere.
            // Lets a poster retrieve/track their own review later (even an
            // anonymous one) without the database ever recording who they are.
            $table->string('poster_token', 64)->nullable()->unique()->after('posted_by');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('poster_token');
        });
    }
};
