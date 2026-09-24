<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug/issue reports: any staff member can file one about the system itself,
 * "manage bug reports" decides who reviews and resolves them. Same shape as
 * employee_requests, which this deliberately mirrors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('message');
            $table->string('severity')->default('Medium');
            // Where it happened — optional, since not every report comes from
            // a page that makes sense to name (e.g. a general complaint).
            $table->string('page_url', 500)->nullable();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('Open')->comment('Open,Resolved,Closed');
            $table->text('response_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'reported_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
