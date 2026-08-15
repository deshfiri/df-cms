<?php

namespace Database\Seeders;

use App\Models\Flow;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Database\Seeder;

/**
 * Demo data for the generic workflow engine — a 3-stage "Content Approval"
 * flow with users assigned per stage and one live item in the first stage.
 * Idempotent and NOT wired into DatabaseSeeder (demo only): run on demand with
 *   php artisan db:seed --class=FlowDemoSeeder
 */
class FlowDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@dfcp.com')->first();

        $flow = Flow::firstOrCreate(
            ['name' => 'Content Approval'],
            ['description' => 'Draft → Review → Publish. Demo workflow for the engine.', 'is_active' => true, 'created_by' => $admin?->id]
        );

        if ($flow->stages()->count() === 0) {
            $stageDefs = [
                ['name' => 'Draft',   'emails' => ['sales@dfcp.com', 'design@dfcp.com']],
                ['name' => 'Review',  'emails' => ['manager@dfcp.com', 'support@dfcp.com']],
                ['name' => 'Publish', 'emails' => ['marketing@dfcp.com']],
            ];

            foreach ($stageDefs as $i => $def) {
                $stage = $flow->stages()->create(['name' => $def['name'], 'position' => $i + 1]);
                $stage->users()->sync(User::whereIn('email', $def['emails'])->pluck('id'));
            }
        }

        // One live item sitting at stage 1 so the Draft-stage users see it in My Queue.
        if ($flow->items()->count() === 0 && $admin) {
            app(FlowService::class)->createItem($flow->refresh(), [
                'title'       => 'Homepage banner copy',
                'description' => 'Write and design the new homepage banner, then send it for review.',
                'note'        => 'Demo item created by seeder',
            ], $admin);
        }

        $this->command?->info("Demo workflow '{$flow->name}' ready ({$flow->stages()->count()} stages, {$flow->items()->count()} item).");
    }
}
