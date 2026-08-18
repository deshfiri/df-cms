<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice messages ride on the existing single-attachment columns; the only thing
 * they need on top is a length.
 *
 * It is stored rather than read from the file because a MediaRecorder clip is
 * written as a live stream with no duration in its header — browsers report
 * Infinity for it until the whole blob has been played through. The recorder
 * knows how long it ran, so it sends that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedSmallInteger('attachment_duration')->nullable()->after('attachment_size');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('attachment_duration');
        });
    }
};
