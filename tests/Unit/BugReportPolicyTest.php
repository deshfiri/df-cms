<?php

namespace Tests\Unit;

use App\Models\BugReport;
use App\Models\User;
use App\Policies\BugReportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * BugReportPolicy in isolation, no HTTP: the exact authorization rules
 * BugReportController delegates to.
 */
class BugReportPolicyTest extends TestCase
{
    use RefreshDatabase;

    private BugReportPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'manage bug reports', 'guard_name' => 'web']);
        $this->policy = new BugReportPolicy();
    }

    private function user(bool $withPermission = false): User
    {
        $user = User::factory()->create(['is_active' => true]);
        if ($withPermission) {
            $user->givePermissionTo('manage bug reports');
        }

        return $user->fresh();
    }

    private function report(int $reporterId, string $status = BugReport::STATUS_OPEN): BugReport
    {
        return BugReport::create([
            'subject' => 'Something is broken', 'message' => 'It just is.',
            'reported_by' => $reporterId, 'status' => $status,
        ]);
    }

    // ── viewAny / create: open to everyone ───────────────────────────────

    public function test_anyone_may_view_the_list_and_file_a_report(): void
    {
        $nobody = $this->user();

        $this->assertTrue($this->policy->viewAny($nobody));
        $this->assertTrue($this->policy->create($nobody));
    }

    // ── view: your own, or with the permission ───────────────────────────

    public function test_the_reporter_may_view_their_own_report(): void
    {
        $reporter = $this->user();
        $report   = $this->report($reporter->id);

        $this->assertTrue($this->policy->view($reporter, $report));
    }

    public function test_a_stranger_without_the_permission_may_not_view_it(): void
    {
        $reporter = $this->user();
        $stranger = $this->user();
        $report   = $this->report($reporter->id);

        $this->assertFalse($this->policy->view($stranger, $report));
    }

    public function test_someone_with_the_permission_may_view_any_report(): void
    {
        $reporter = $this->user();
        $manager  = $this->user(withPermission: true);
        $report   = $this->report($reporter->id);

        $this->assertTrue($this->policy->view($manager, $report));
    }

    // ── respond: the permission only ─────────────────────────────────────

    public function test_only_someone_with_the_permission_may_respond(): void
    {
        $reporter = $this->user();
        $manager  = $this->user(withPermission: true);
        $report   = $this->report($reporter->id);

        $this->assertFalse($this->policy->respond($reporter, $report), "filing your own report isn't a licence to resolve it");
        $this->assertTrue($this->policy->respond($manager, $report));
    }

    // ── delete: your own while open, or the permission any time ─────────

    public function test_the_reporter_may_delete_their_own_open_report(): void
    {
        $reporter = $this->user();
        $report   = $this->report($reporter->id);

        $this->assertTrue($this->policy->delete($reporter, $report));
    }

    public function test_the_reporter_may_not_delete_it_once_reviewed(): void
    {
        $reporter = $this->user();
        $resolved = $this->report($reporter->id, BugReport::STATUS_RESOLVED);

        $this->assertFalse($this->policy->delete($reporter, $resolved));
    }

    public function test_a_stranger_may_not_delete_someone_elses_open_report(): void
    {
        $reporter = $this->user();
        $stranger = $this->user();
        $report   = $this->report($reporter->id);

        $this->assertFalse($this->policy->delete($stranger, $report));
    }

    public function test_someone_with_the_permission_may_delete_any_report_regardless_of_status(): void
    {
        $reporter = $this->user();
        $manager  = $this->user(withPermission: true);
        $resolved = $this->report($reporter->id, BugReport::STATUS_RESOLVED);

        $this->assertTrue($this->policy->delete($manager, $resolved));
    }
}
