<?php

use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\TaskInvolvementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured task history, and who was involved in each task.
 *
 * task_activities gains a normalized `event` and `meta` alongside its existing
 * human wording. task_involvements is a projection of that history — one row
 * per person per task — which performance reads. See TaskInvolvementService for
 * the rules and the formula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_activities', function (Blueprint $table) {
            $table->string('event', 40)->nullable()->after('action');
            $table->json('meta')->nullable()->after('event');

            $table->index(['task_id', 'event']);
            $table->index(['user_id', 'event', 'created_at']);
        });

        Schema::create('task_involvements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->decimal('points', 8, 2)->default(0);
            $table->decimal('review_points', 8, 2)->default(0);
            // Share of the task's work credit, 0–1, derived from points.
            $table->decimal('share', 5, 4)->default(0);
            $table->unsignedInteger('events_count')->default(0);
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->dateTime('first_activity_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            // Points per event type, e.g. {"started":2,"submitted":4} — the audit trail of the score.
            $table->json('breakdown')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        // Give old history structured events, then build involvement for every task.
        TaskActivity::whereNull('event')->orderBy('id')->chunkById(500, function ($activities) {
            foreach ($activities as $activity) {
                [$event, $meta] = TaskInvolvementService::eventOf($activity);
                $activity->forceFill(['event' => $event, 'meta' => $meta ?: null])->saveQuietly();
            }
        });

        $service = app(TaskInvolvementService::class);
        Task::withTrashed()->orderBy('id')->chunkById(200, function ($tasks) use ($service) {
            foreach ($tasks as $task) {
                $service->rebuild($task);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_involvements');

        Schema::table('task_activities', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'event', 'created_at']);
            $table->dropIndex(['task_id', 'event']);
            $table->dropColumn(['event', 'meta']);
        });
    }
};
