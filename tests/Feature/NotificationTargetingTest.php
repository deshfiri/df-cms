<?php

namespace Tests\Feature;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Notifications\RequestResolved;
use App\Notifications\RequestSubmitted;
use App\Services\EmployeeRequestService;
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
 * roles regardless of permission, and most included the actor.
 */
class NotificationTargetingTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeRequestService $requests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = app(EmployeeRequestService::class);

        Permission::firstOrCreate(['name' => 'manage requests', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
    }

    private function manager(bool $withPermission = true): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Manager');

        if ($withPermission) {
            $user->givePermissionTo('manage requests');
        }

        return $user->fresh();
    }

    public function test_the_person_filing_a_request_is_not_notified_about_it(): void
    {
        Notification::fake();

        // A manager filing their own request is in the approver role, so the
        // old role-wide fan-out notified them about their own submission.
        $filer = $this->manager();
        $other = $this->manager();

        $this->requests->create([
            'subject' => 'Need a day off',
            'message' => 'Family thing.',
        ], $filer);

        Notification::assertNotSentTo($filer, RequestSubmitted::class);
        Notification::assertSentTo($other, RequestSubmitted::class);
    }

    public function test_role_members_without_the_permission_are_not_notified(): void
    {
        Notification::fake();

        $canAct  = $this->manager();
        $cannot  = $this->manager(withPermission: false);
        $filer   = User::factory()->create(['is_active' => true]);

        $this->requests->create([
            'subject' => 'Need a laptop',
            'message' => 'Mine died.',
        ], $filer);

        Notification::assertSentTo($canAct, RequestSubmitted::class);
        Notification::assertNotSentTo($cannot, RequestSubmitted::class);
    }

    public function test_inactive_staff_are_never_notified(): void
    {
        Notification::fake();

        $active   = $this->manager();
        $inactive = $this->manager();
        $inactive->update(['is_active' => false]);

        $this->requests->create([
            'subject' => 'Something',
            'message' => 'Anything.',
        ], User::factory()->create(['is_active' => true]));

        Notification::assertSentTo($active, RequestSubmitted::class);
        Notification::assertNotSentTo($inactive, RequestSubmitted::class);
    }

    public function test_resolving_your_own_request_does_not_notify_you(): void
    {
        Notification::fake();

        $manager = $this->manager();

        $request = $this->requests->create([
            'subject' => 'Self-served',
            'message' => 'I will handle it.',
        ], $manager);

        $this->requests->respond($request, EmployeeRequest::STATUS_APPROVED, null, $manager);

        Notification::assertNotSentTo($manager, RequestResolved::class);
    }

    public function test_resolving_someone_elses_request_still_notifies_them(): void
    {
        Notification::fake();

        $filer   = User::factory()->create(['is_active' => true]);
        $manager = $this->manager();

        $request = $this->requests->create([
            'subject' => 'Please approve',
            'message' => 'Thanks.',
        ], $filer);

        $this->requests->respond($request, EmployeeRequest::STATUS_APPROVED, null, $manager);

        Notification::assertSentTo($filer, RequestResolved::class);
    }

    public function test_a_missing_permission_falls_back_to_role_rather_than_silence(): void
    {
        Notification::fake();

        // If a permission is renamed or dropped, over-notifying is recoverable;
        // silently dropping "there is work waiting for you" is not.
        Permission::where('name', 'manage requests')->delete();
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = $this->manager(withPermission: false);

        $this->requests->create([
            'subject' => 'Still needs to arrive',
            'message' => 'Yes.',
        ], User::factory()->create(['is_active' => true]));

        Notification::assertSentTo($manager, RequestSubmitted::class);
    }
}
