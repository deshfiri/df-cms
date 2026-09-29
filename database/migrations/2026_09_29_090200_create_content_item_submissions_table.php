<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only: every submission or resubmission is a new row, mirroring
 * TaskAttachment. Keeps full timestamps() (not created_at-only like
 * TaskRevision) because file_path/disk may be repointed later by the
 * provider-upload queue — see UploadStaging / PushUploadToProvider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_item_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->string('file_path')->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('content_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_submissions');
    }
};
