<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes matching the query shapes the app actually issues.
 *
 * Every column here already had a single-column index where it needed one; what
 * was missing was the *pairs*. MySQL uses one index per table reference, so
 * `where assigned_to = ? and due_date between ? and ?` narrows by employee and
 * then scans their rows by hand. These are drawn from queries observed in a
 * request profile, not from guesswork — the performance scoreboard alone issues
 * three of these shapes per employee.
 */
return new class extends Migration
{
    /**
     * @var array<string,array<string,array<int,string>>>  table => index name => columns
     */
    private const INDEXES = [
        'tasks' => [
            // Scoreboard: tasks due in a period, per employee.
            'tasks_assigned_due_idx'        => ['assigned_to', 'due_date'],
            // Scoreboard: on-time completion, per employee.
            'tasks_assigned_status_idx'     => ['assigned_to', 'status'],
            // Workload board: active tasks per employee.
            'tasks_status_due_idx'          => ['status', 'due_date'],
        ],
        'payments' => [
            // Dashboard revenue: paid rows inside a date range.
            'payments_status_date_idx'      => ['status', 'payment_date'],
        ],
        'messages' => [
            // Thread paging, and the unread badge the layout renders per page.
            'messages_conv_created_idx'     => ['conversation_id', 'created_at'],
            'messages_conv_read_idx'        => ['conversation_id', 'read_at'],
        ],
        'activity_logs' => [
            // Client timeline: newest first for one client.
            'activity_client_created_idx'   => ['client_id', 'created_at'],
        ],
        'flow_items' => [
            // My Queue and the overdue sweep.
            'flow_items_status_stage_idx'   => ['status', 'current_stage_id'],
            'flow_items_client_status_idx'  => ['client_id', 'status'],
        ],
        'client_meetings' => [
            // Calendar range queries and the reminder sweep.
            'meetings_scheduled_status_idx' => ['scheduled_at', 'status'],
        ],
        'calls' => [
            // The unseen-missed badge, per callee.
            'calls_callee_seen_idx'         => ['callee_id', 'callee_seen_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                // Skip anything already present — the calls index, for one, was
                // created with its own table.
                if ($this->indexExists($table, $name) || !$this->hasColumns($table, $columns)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if ($this->indexExists($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($index) => $index['name'] === $name);
    }

    /** @param array<int,string> $columns */
    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
