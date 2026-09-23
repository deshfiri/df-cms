<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\DailyTarget;
use App\Models\User;
use App\Services\PerformanceConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PerformanceConfigService::setDailyTargets() / clearDailyTargets() called
 * directly, bypassing the controller's authorization and request validation
 * entirely — those are covered in Tests\Feature\DailyTargetPerformanceTest.
 * This is the write path's own replace/delete semantics in isolation.
 */
class PerformanceConfigServiceDailyTargetTest extends TestCase
{
    use RefreshDatabase;

    private User $sam;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sam     = User::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->manager); // ActivityLogService stamps Auth::id()
    }

    private function service(): PerformanceConfigService
    {
        return app(PerformanceConfigService::class);
    }

    public function test_it_creates_one_row_per_scope(): void
    {
        $this->service()->setDailyTargets($this->sam->id, ['task' => 5, 'workflow' => 3]);

        $this->assertSame(2, DailyTarget::where('user_id', $this->sam->id)->count());
        $this->assertSame(5, DailyTarget::where('user_id', $this->sam->id)->where('scope', 'task')->value('target_quantity'));
        $this->assertSame(3, DailyTarget::where('user_id', $this->sam->id)->where('scope', 'workflow')->value('target_quantity'));
    }

    public function test_calling_it_again_replaces_rather_than_adds(): void
    {
        $this->service()->setDailyTargets($this->sam->id, ['task' => 5, 'workflow' => 3]);
        $this->service()->setDailyTargets($this->sam->id, ['task' => 8]);

        $this->assertSame(1, DailyTarget::where('user_id', $this->sam->id)->count());
        $this->assertSame(8, DailyTarget::where('user_id', $this->sam->id)->where('scope', 'task')->value('target_quantity'));
        $this->assertDatabaseMissing('daily_targets', ['user_id' => $this->sam->id, 'scope' => 'workflow']);
    }

    public function test_it_does_not_disturb_another_users_targets(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $this->service()->setDailyTargets($other->id, ['task' => 9]);

        $this->service()->setDailyTargets($this->sam->id, ['workflow' => 4]);

        $this->assertSame(9, DailyTarget::where('user_id', $other->id)->where('scope', 'task')->value('target_quantity'));
    }

    public function test_it_is_recorded_to_the_activity_log(): void
    {
        $this->service()->setDailyTargets($this->sam->id, ['task' => 5]);

        $this->assertTrue(ActivityLog::where('module', 'Performance')->where('action', 'Daily Targets Set')->exists());
    }

    public function test_clearing_removes_every_scope(): void
    {
        $this->service()->setDailyTargets($this->sam->id, ['task' => 5, 'workflow' => 3]);

        $this->service()->clearDailyTargets($this->sam);

        $this->assertSame(0, DailyTarget::where('user_id', $this->sam->id)->count());
    }

    public function test_clearing_an_already_empty_user_is_a_silent_no_op(): void
    {
        $before = ActivityLog::count();

        $this->service()->clearDailyTargets($this->sam);

        $this->assertSame($before, ActivityLog::count());
    }
}
