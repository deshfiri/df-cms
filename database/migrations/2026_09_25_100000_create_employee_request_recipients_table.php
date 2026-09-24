<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A request now names who it's for, instead of going out to everyone who
 * holds "manage requests" — that broadcast meant a request addressed to one
 * manager was visible to (and actionable by) every other manager too.
 *
 * Existing requests predate this and have nobody named, so they'd otherwise
 * become invisible to anyone but the person who filed them — backfilled to
 * whoever currently holds "manage requests", mirroring who would have seen
 * them under the old rule, so nothing already pending is stranded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_request_recipients', function (Blueprint $table) {
            $table->foreignId('employee_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_request_id', 'user_id']);
        });

        // Nothing to backfill on a fresh install — and permission() throws
        // if the permission row itself doesn't exist yet (a brand new test
        // database runs this migration before any seeder creates it).
        $permissionExists = \Spatie\Permission\Models\Permission::where('name', 'manage requests')
            ->where('guard_name', 'web')->exists();

        if (!$permissionExists) {
            return;
        }

        // Spatie's permission() scope (not a raw join) so this covers a grant
        // held directly or inherited through a role either way, exactly like
        // the app's own permission checks do.
        $approvers = \App\Models\User::query()->permission('manage requests')->pluck('id');

        if ($approvers->isEmpty()) {
            return;
        }

        // Only what's still actionable — an approved or rejected request
        // needs no recipients backfilled; the requester can already see it.
        DB::table('employee_requests')->where('status', 'Pending')->orderBy('id')
            ->each(function ($request) use ($approvers) {
                DB::table('employee_request_recipients')->insert(
                    $approvers->map(fn ($userId) => [
                        'employee_request_id' => $request->id,
                        'user_id'              => $userId,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_request_recipients');
    }
};
