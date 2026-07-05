<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->integer('updated_rows')->default(0)->after('success_rows');
            $table->integer('skipped_rows')->default(0)->after('updated_rows');
            $table->unsignedInteger('import_duration_seconds')->nullable()->after('skipped_rows');
            $table->json('validation_errors')->nullable()->after('errors');
        });

        // Full-text search index on clients for global search performance.
        // Sqlite (used by the test suite) has no fulltext index support, so
        // this is skipped there — global search itself isn't exercised by
        // that driver either.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('clients', function (Blueprint $table) {
                $table->fullText(['client_name', 'brand_name', 'dfid_number', 'website', 'remarks'], 'clients_fulltext');
            });

            // Full-text on notes
            Schema::table('client_notes', function (Blueprint $table) {
                $table->fullText(['note'], 'notes_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropColumn(['updated_rows', 'skipped_rows', 'import_duration_seconds', 'validation_errors']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropFullText('clients_fulltext');
            });
            Schema::table('client_notes', function (Blueprint $table) {
                $table->dropFullText('notes_fulltext');
            });
        }
    }
};
