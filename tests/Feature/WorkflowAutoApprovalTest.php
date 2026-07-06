<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowStageException;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientStageProgress;
use App\Models\Payment;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\StageAwaitingApproval;
use App\Notifications\StageReadyForDepartment;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowAutoApprovalTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = app(WorkflowService::class);

        // A migration auto-seeds the real 19-stage pipeline into every fresh
        // database (including this test's). Clear it so each test builds its
        // own isolated, minimal pipeline instead of interacting with it.
        WorkflowStage::query()->delete();

        // Spatie's hasPermissionTo()/User::role() throw if the permission or
        // role doesn't exist anywhere yet, rather than just returning false.
        Permission::firstOrCreate(['name' => 'manage-workflow', 'guard_name' => 'web']);
    }

    private function makeClient(bool $paid = true): Client
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category-' . uniqid(), 'status' => true]);

        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'Test Client',
            'brand_name'  => 'Test Brand',
            'category_id' => $category->id,
        ]);

        if ($paid) {
            Payment::create(['client_id' => $client->id, 'status' => 'Paid', 'amount' => 100]);
        }

        return $client;
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }

    private function makeStage(string $code, string $department, int $sortOrder, bool $requiresApproval = false): WorkflowStage
    {
        // notifyNextDepartment() looks users up via User::role($department),
        // which throws if that role doesn't exist anywhere yet.
        Role::firstOrCreate(['name' => $department, 'guard_name' => 'web']);

        return WorkflowStage::create([
            'name'              => ucfirst(str_replace('_', ' ', $code)),
            'code'              => $code,
            'department'        => $department,
            'requires_approval' => $requiresApproval,
            'sort_order'        => $sortOrder,
            'status'            => true,
        ]);
    }

    public function test_marking_a_stage_done_immediately_approves_it_and_unlocks_the_next_stage(): void
    {
        $client = $this->makeClient();
        $stage1 = $this->makeStage('stage_one', 'Sales', 1);
        $stage2 = $this->makeStage('stage_two', 'Design', 2);
        $salesUser = $this->makeUser('Sales');

        $before = collect($this->workflow->getTimeline($client));
        $this->assertFalse($before->firstWhere('stage.id', $stage1->id)['locked']);
        $this->assertTrue($before->firstWhere('stage.id', $stage2->id)['locked']);

        $progress = $this->workflow->submitStage($client, $stage1->id, $salesUser);

        $this->assertSame(ClientStageProgress::STATUS_APPROVED, $progress->status);
        $this->assertTrue($progress->is_completed);
        $this->assertNotNull($progress->completed_at);

        $after = collect($this->workflow->getTimeline($client));
        $this->assertFalse($after->firstWhere('stage.id', $stage2->id)['locked']);
    }

    public function test_a_stage_stays_locked_until_the_previous_stage_is_approved(): void
    {
        $client = $this->makeClient();
        $this->makeStage('stage_one', 'Sales', 1);
        $stage2 = $this->makeStage('stage_two', 'Design', 2);
        $designUser = $this->makeUser('Design');

        $this->expectException(WorkflowStageException::class);
        $this->expectExceptionMessage('This stage is locked until the previous stage is approved.');

        $this->workflow->submitStage($client, $stage2->id, $designUser);
    }

    public function test_only_the_owning_department_can_submit_a_stage(): void
    {
        $client = $this->makeClient();
        $stage = $this->makeStage('stage_one', 'Sales', 1);
        $designUser = $this->makeUser('Design');

        $this->expectException(WorkflowStageException::class);
        $this->expectExceptionMessage('Only the Sales team can work on this stage.');

        $this->workflow->submitStage($client, $stage->id, $designUser);
    }

    public function test_super_admin_can_submit_any_stage_regardless_of_department(): void
    {
        $client = $this->makeClient();
        $stage = $this->makeStage('stage_one', 'Sales', 1);
        $admin = $this->makeUser('Super Admin');

        $progress = $this->workflow->submitStage($client, $stage->id, $admin);

        $this->assertSame(ClientStageProgress::STATUS_APPROVED, $progress->status);
    }

    public function test_workflow_cannot_progress_without_a_payment_on_record(): void
    {
        $client = $this->makeClient(paid: false);
        $stage = $this->makeStage('stage_one', 'Sales', 1);
        $salesUser = $this->makeUser('Sales');

        $this->expectException(WorkflowStageException::class);
        $this->expectExceptionMessage('This client has no payment on record — the workflow cannot proceed until at least a partial payment is made.');

        $this->workflow->submitStage($client, $stage->id, $salesUser);
    }

    public function test_the_acting_user_is_not_notified_about_their_own_stage_submission(): void
    {
        Notification::fake();

        $client = $this->makeClient();
        $stage1 = $this->makeStage('stage_one', 'Sales', 1);
        $this->makeStage('stage_two', 'Sales', 2);

        $actor = $this->makeUser('Sales');
        $teammate = $this->makeUser('Sales');

        $this->workflow->submitStage($client, $stage1->id, $actor);

        Notification::assertNotSentTo($actor, StageReadyForDepartment::class);
        Notification::assertSentTo($teammate, StageReadyForDepartment::class);
    }

    public function test_submitting_a_stage_does_not_crash_when_the_next_stages_department_has_no_matching_role(): void
    {
        $client = $this->makeClient();
        $stage1 = $this->makeStage('stage_one', 'Sales', 1);

        // Deliberately bypass makeStage()'s role auto-creation: a department
        // label on a Workflow Stage is free text and isn't guaranteed to match
        // an existing role (e.g. "Admin" vs. the real "Super Admin" role).
        WorkflowStage::create([
            'name' => 'Stage Two', 'code' => 'stage_two', 'department' => 'Admin',
            'requires_approval' => false, 'sort_order' => 2, 'status' => true,
        ]);

        $salesUser = $this->makeUser('Sales');

        $progress = $this->workflow->submitStage($client, $stage1->id, $salesUser);

        $this->assertSame(ClientStageProgress::STATUS_APPROVED, $progress->status);
    }

    public function test_a_stage_requiring_approval_stays_submitted_and_notifies_the_approving_department(): void
    {
        Notification::fake();
        Permission::firstOrCreate(['name' => 'approve-stage', 'guard_name' => 'web']);

        $client = $this->makeClient();
        $stage  = $this->makeStage('stage_one', 'Sales', 1, requiresApproval: true);

        $submitter = $this->makeUser('Sales');
        $approver  = $this->makeUser('Sales');
        $approver->givePermissionTo('approve-stage');
        $bystander = $this->makeUser('Sales'); // same department, but no approve-stage permission

        $progress = $this->workflow->submitStage($client, $stage->id, $submitter);

        $this->assertSame(ClientStageProgress::STATUS_SUBMITTED, $progress->status);
        $this->assertFalse($progress->is_completed);

        Notification::assertSentTo($approver, StageAwaitingApproval::class);
        Notification::assertNotSentTo($submitter, StageAwaitingApproval::class);
        Notification::assertNotSentTo($bystander, StageAwaitingApproval::class);
    }

    public function test_approving_a_submitted_stage_unlocks_and_notifies_the_next_department(): void
    {
        Notification::fake();
        Permission::firstOrCreate(['name' => 'approve-stage', 'guard_name' => 'web']);

        $client = $this->makeClient();
        $stage1 = $this->makeStage('stage_one', 'Sales', 1, requiresApproval: true);
        $stage2 = $this->makeStage('stage_two', 'Design', 2);

        $submitter = $this->makeUser('Sales');
        $approver  = $this->makeUser('Sales');
        $approver->givePermissionTo('approve-stage');
        $designUser = $this->makeUser('Design');

        $this->workflow->submitStage($client, $stage1->id, $submitter);
        $progress = $this->workflow->approveStage($client, $stage1->id, $approver);

        $this->assertSame(ClientStageProgress::STATUS_APPROVED, $progress->status);
        $this->assertTrue($progress->is_completed);

        $timeline = collect($this->workflow->getTimeline($client));
        $this->assertFalse($timeline->firstWhere('stage.id', $stage2->id)['locked']);

        Notification::assertSentTo($designUser, StageReadyForDepartment::class);
    }

    public function test_a_user_without_approve_stage_permission_cannot_approve(): void
    {
        Permission::firstOrCreate(['name' => 'approve-stage', 'guard_name' => 'web']);

        $client = $this->makeClient();
        $stage  = $this->makeStage('stage_one', 'Sales', 1, requiresApproval: true);
        $submitter = $this->makeUser('Sales');

        $this->workflow->submitStage($client, $stage->id, $submitter);

        $this->expectException(WorkflowStageException::class);
        $this->expectExceptionMessage('You do not have permission to approve this stage.');

        $this->workflow->approveStage($client, $stage->id, $submitter);
    }

    public function test_rejecting_a_submitted_stage_allows_resubmission(): void
    {
        Permission::firstOrCreate(['name' => 'approve-stage', 'guard_name' => 'web']);

        $client = $this->makeClient();
        $stage  = $this->makeStage('stage_one', 'Sales', 1, requiresApproval: true);

        $submitter = $this->makeUser('Sales');
        $approver  = $this->makeUser('Sales');
        $approver->givePermissionTo('approve-stage');

        $this->workflow->submitStage($client, $stage->id, $submitter);
        $rejected = $this->workflow->requestRevision($client, $stage->id, $approver, 'Missing details');

        $this->assertSame(ClientStageProgress::STATUS_NEED_REVISION, $rejected->status);
        $this->assertSame('Missing details', $rejected->rejection_reason);

        $resubmitted = $this->workflow->submitStage($client, $stage->id, $submitter, 'Added the missing details');

        $this->assertSame(ClientStageProgress::STATUS_SUBMITTED, $resubmitted->status);
        $this->assertNull($resubmitted->rejection_reason);
    }

    public function test_admin_toggle_override_does_not_notify_the_next_department(): void
    {
        // Documents current, deliberate behavior: toggleStage() is a manual
        // admin correction tool, not a real workflow progression event, so it
        // intentionally stays silent (unlike submitStage/approveStage).
        Notification::fake();

        $client = $this->makeClient();
        $stage1 = $this->makeStage('stage_one', 'Sales', 1);
        $this->makeStage('stage_two', 'Design', 2);
        $designUser = $this->makeUser('Design');
        $admin = $this->makeUser('Super Admin');

        // toggleStage() reads the actor off Auth::id() internally rather than
        // taking a User param like the other service methods.
        $this->actingAs($admin);
        $this->workflow->toggleStage($client->id, $stage1->id, true);

        Notification::assertNotSentTo($designUser, StageReadyForDepartment::class);
    }
}
