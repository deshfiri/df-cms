<?php

namespace Tests\Unit;

use App\Models\MonthlyPerformanceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PerformanceCalculationService gained a constructor dependency
 * (ClientProgressService) when Output Volume's Workflow Items scope was
 * rebuilt around client progress. The scoreboard resolves the service
 * through the container either way, but `performance:snapshot` is this
 * app's one console/cron entry point that also does — this is a smoke test
 * that the whole command still runs end to end, not a re-test of the
 * scoring math itself (see WorkflowVolumeClientProgressTest for that).
 */
class PerformanceSnapshotCommandSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_snapshot_command_still_runs_end_to_end(): void
    {
        User::factory()->create(['is_active' => true]);

        Artisan::call('performance:snapshot', ['period' => '2026-09']);

        $this->assertStringContainsString('Snapshotting performance', Artisan::output());
    }

    public function test_it_writes_nothing_when_nobody_has_scorable_activity(): void
    {
        User::factory()->create(['is_active' => true]);

        Artisan::call('performance:snapshot', ['period' => '2026-09']);

        $this->assertSame(0, MonthlyPerformanceSnapshot::count());
    }
}
