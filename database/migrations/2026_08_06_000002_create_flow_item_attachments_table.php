<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliverables attached to a workflow item — uploaded files (images/pdf/video/
 * any), external links (e.g. video URLs), or plain text notes. They belong to
 * the item, so they travel forward with it to every subsequent stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_item_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_item_id')->constrained('flow_items')->cascadeOnDelete();
            $table->string('kind'); // file | link | note
            $table->string('title')->nullable();
            // file
            $table->string('original_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // link
            $table->string('url', 2048)->nullable();
            // note
            $table->text('body')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('flow_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_item_attachments');
    }
};
