<?php

namespace Tests\Unit;

use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * database/migrations/2026_09_26_120000_add_completed_by_to_flow_items_table.php
 *
 * Existing completed items predate this column and have assigned_to already
 * cleared — the only surviving record of who finished them is the completing
 * FlowTransition (to_stage_id null). backfill() reconstructs completed_by
 * from that row rather than leaving history stranded at null.
 *
 * Exercised directly against pre-existing rows, not by replaying the whole
 * migration's schema change — flow_transitions.flow_item_id cascadeOnDelete()s,
 * and SQLite can only add this column's foreign key by rebuilding the whole
 * flow_items table, which would wipe the very transition rows being tested.
 * MySQL has no such rebuild-on-ALTER behavior; that's a SQLite test-only
 * artifact, not a real risk to the production data this backfills.
 */
class FlowItemCompletedByBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function backfill(): void
    {
        (require database_path('migrations/2026_09_26_120000_add_completed_by_to_flow_items_table.php'))->backfill();
    }

    public function test_a_completed_items_completer_is_reconstructed_from_its_transition(): void
    {
        $admin  = User::factory()->create(['is_active' => true]);
        $worker = User::factory()->create(['is_active' => true]);
        $flow   = Flow::create(['name' => 'x', 'is_active' => true, 'created_by' => $admin->id]);
        $stage  = $flow->stages()->create(['name' => 'Only', 'position' => 1]);

        $item = FlowItem::create([
            'flow_id' => $flow->id, 'title' => 'x', 'status' => FlowItem::STATUS_COMPLETED,
            'assigned_to' => null, 'created_by' => $admin->id, 'completed_at' => now(),
        ]);
        DB::table('flow_transitions')->insert([
            'flow_item_id' => $item->id, 'from_stage_id' => $stage->id, 'to_stage_id' => null,
            'moved_by' => $worker->id, 'created_at' => now(),
        ]);

        $this->backfill();

        $this->assertSame($worker->id, $item->fresh()->completed_by);
    }

    public function test_an_item_with_no_completing_transition_is_left_null_not_guessed(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $flow  = Flow::create(['name' => 'x', 'is_active' => true, 'created_by' => $admin->id]);

        $item = FlowItem::create([
            'flow_id' => $flow->id, 'title' => 'x', 'status' => FlowItem::STATUS_COMPLETED,
            'assigned_to' => null, 'created_by' => $admin->id, 'completed_at' => now(),
        ]);

        $this->backfill();

        $this->assertNull($item->fresh()->completed_by);
    }

    public function test_an_open_items_completed_by_is_never_touched(): void
    {
        $admin  = User::factory()->create(['is_active' => true]);
        $worker = User::factory()->create(['is_active' => true]);
        $flow   = Flow::create(['name' => 'x', 'is_active' => true, 'created_by' => $admin->id]);

        $item = FlowItem::create([
            'flow_id' => $flow->id, 'title' => 'x', 'status' => FlowItem::STATUS_OPEN,
            'assigned_to' => $worker->id, 'created_by' => $admin->id,
        ]);

        $this->backfill();

        $this->assertNull($item->fresh()->completed_by);
    }
}
