<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a task's submission must include a file.
 *
 * Set by whoever creates or edits the task — a design brief needs the design
 * handed in, a phone call does not. Enforced in TaskService::submitForReview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('requires_attachment')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('requires_attachment');
        });
    }
};
