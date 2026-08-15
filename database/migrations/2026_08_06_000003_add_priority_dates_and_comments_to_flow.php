<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_items', function (Blueprint $table) {
            $table->string('priority')->default('Normal')->after('description'); // Low | Normal | High | Urgent
            $table->date('due_date')->nullable()->after('priority');
            $table->index('due_date');
        });

        Schema::create('flow_item_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_item_id')->constrained('flow_items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('flow_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_item_comments');
        Schema::table('flow_items', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
            $table->dropColumn(['priority', 'due_date']);
        });
    }
};
