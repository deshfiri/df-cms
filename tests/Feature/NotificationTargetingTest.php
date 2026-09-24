<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\User;
use App\Notifications\BugReportResolved;
use App\Notifications\BugReportSubmitted;
use App\Services\BugReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two rules every notification in the app now follows:
 *
 *   1. you are never told about your own work
 *   2. only people who can actually act on it are told
 *
 * Recipient selection had drifted apart across services — some notified whole
 * roles regardless of permission, and most included the actor. Exercised here
 * through BugReportService, one of several services sharing NotifiesStaff —
 * see that trait's own docblock for the full list. (EmployeeRequestService
 * used to be this file's vehicle too, but requests are no longer sent to a
 * role/permission at all — see EmployeeRequestTest for that module's own
 * targeting rules: a specific, chosen set of recipients instead.)
 */
class NotificationTargetingTest extends TestCase
{
    use RefreshDatabase;

    private BugReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app(BugReportService::class);

        Permission::firstOrCreate(['name' => 'manage bug reports', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
    }

    private function manager(bool $withPermission = true): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Manager');

        if ($withPermission) {
            $user->givePermissionTo('manage bug reports');
        }

        return $user->fresh();
    }

    public function test_the_person_filing_a_report_is_not_notified_about_it(): void
    {
        Notification::fake();

        // A manager filing their own report is in the reviewer role, so the
        // old role-wide fan-out notified them about their own submission.
        $filer = $this->manager();
        $other = $this->manager();

        $this->reports->create([
            'subject' => 'Button misaligned',
            'message' => 'On the tasks page.',
        ], $filer);

        Notification::assertNotSentTo($filer, BugReportSubmitted::class);
        Notification::assertSentTo($other, BugReportSubmitted::class);
    }

    public function test_role_members_without_the_permission_are_not_notified(): void
    {
        Notification::fake();

        $canAct = $this->manager();
        $cannot = $this->manager(withPermission: false);
        $filer  = User::factory()->create(['is_active' => true]);

        $this->reports->create([
            'subject' => 'Page will not load',
            'message' => 'Blank screen.',
        ], $filer);

        Notification::assertSentTo($canAct, BugReportSubmitted::class);
        Notification::assertNotSentTo($cannot, BugReportSubmitted::class);
    }

    public function test_inactive_staff_are_never_notified(): void
    {
        Notification::fake();

        $active   = $this->manager();
        $inactive = $this->manager();
        $inactive->update(['is_active' => false]);

        $this->reports->create([
            'subject' => 'Something',
            'message' => 'Anything.',
        ], User::factory()->create(['is_active' => true]));

        Notification::assertSentTo($active, BugReportSubmitted::class);
        Notification::assertNotSentTo($inactive, BugReportSubmitted::class);
    }

    public function test_resolving_your_own_report_does_not_notify_you(): void
    {
        Notification::fake();

        $manager = $this->manager();

        $report = $this->reports->create([
            'subject' => 'Self-served',
            'message' => 'I will fix it myself.',
        ], $manager);

        $this->reports->respond($report, BugReport::STATUS_RESOLVED, null, $manager);

        Notification::assertNotSentTo($manager, BugReportResolved::class);
    }

    public function test_resolving_someone_elses_report_still_notifies_them(): void
    {
        Notification::fake();

        $filer   = User::factory()->create(['is_active' => true]);
        $manager = $this->manager();

        $report = $this->reports->create([
            'subject' => 'Please fix',
            'message' => 'Thanks.',
        ], $filer);

        $this->reports->respond($report, BugReport::STATUS_RESOLVED, null, $manager);

        Notification::assertSentTo($filer, BugReportResolved::class);
    }

    public function test_a_missing_permission_falls_back_to_role_rather_than_silence(): void
    {
        Notification::fake();

        // If a permission is renamed or dropped, over-notifying is recoverable;
        // silently dropping "there is work waiting for you" is not.
        Permission::where('name', 'manage bug reports')->delete();
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = $this->manager(withPermission: false);

        $this->reports->create([
            'subject' => 'Still needs to arrive',
            'message' => 'Yes.',
        ], User::factory()->create(['is_active' => true]));

        Notification::assertSentTo($manager, BugReportSubmitted::class);
    }
}
