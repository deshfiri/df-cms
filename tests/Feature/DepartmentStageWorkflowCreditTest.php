<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientStageProgress;
use App\Models\Payment;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\MeetingService;
use App\Services\Performance\PerformanceCalculationService;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The department pipeline (WorkflowStage/ClientStageProgress) is a separate,
 * older system from the Flow engine, but it's still actively worked today —
 * often "auto-claimed": a single Submit click auto-approves a stage that
 * doesn't require approval, and a meeting being booked/completed auto-submits
 * or auto-approves its stage with nobody clicking a workflow button at all.
 * None of that used to count toward Output Volume's "Workflow Items" scope.
 * Now every qualifying submission earns the submitter a credit, blended into
 * the same scope the Flow engine already feeds — whether it was a manual
 * click or an automatic side effect of something else.
 */
class DepartmentStageWorkflowCreditTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowService $workflow;
    private PerformanceCalculationService $performance;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->workflow = app(WorkflowService::class);
        $this->performance = app(PerformanceCalculationService::class);

        // A migration auto-seeds a real 19-stage pipeline into every fresh
        // database. Clear it so each test builds its own isolated pipeline —
        // force-deleted, not soft-deleted, since a couple of these tests
        // reuse a real seeded code ('meeting_scheduled') that a soft delete
        // would still collide with on re-insert.
        WorkflowStage::withTrashed()->forceDelete();

        Permission::firstOrCreate(['name' => 'manage-workflow', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'approve-stage', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => 'web']);
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Client ' . uniqid(), 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
        Payment::create(['client_id' => $client->id, 'status' => 'Paid', 'amount' => 100]);

        return $client;
    }

    private function worker(string $role = 'Sales'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        $user->givePermissionTo('view clients');

        return $user;
    }

    private function stage(string $code, string $department, int $sortOrder, bool $requiresApproval = false): WorkflowStage
    {
        Role::firstOrCreate(['name' => $department, 'guard_name' => 'web']);

        return WorkflowStage::create([
            'name' => ucfirst(str_replace('_', ' ', $code)), 'code' => $code, 'department' => $department,
            'requires_approval' => $requiresApproval, 'sort_order' => $sortOrder, 'status' => true,
        ]);
    }

    public function test_manually_submitting_an_auto_approved_stage_credits_the_submitter(): void
    {
        $client = $this->client();
        $stage = $this->stage('brief', 'Sales', 1, requiresApproval: false);
        $worker = $this->worker('Sales');

        $this->workflow->submitStage($client, $stage->id, $worker);

        $scope = $this->performance->outputVolume($worker->fresh(), date('Y-m'))['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    /** Auto-approval on submit is still "the submitter did the work" — a stage requiring separate approval credits the submitter just the same. */
    public function test_submitting_a_stage_that_still_awaits_approval_also_credits_the_submitter(): void
    {
        $client = $this->client();
        $stage = $this->stage('design', 'Design', 1, requiresApproval: true);
        $worker = $this->worker('Design');

        $progress = $this->workflow->submitStage($client, $stage->id, $worker);
        $this->assertSame(ClientStageProgress::STATUS_SUBMITTED, $progress->status);

        $scope = $this->performance->outputVolume($worker->fresh(), date('Y-m'))['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    /** Approving is a separate role from submitting — it doesn't add a second credit for the same stage. */
    public function test_approving_a_stage_does_not_credit_the_approver_separately(): void
    {
        $client = $this->client();
        $stage = $this->stage('design', 'Design', 1, requiresApproval: true);
        $submitter = $this->worker('Design');
        $approver = tap($this->worker('Design'))->givePermissionTo('approve-stage');

        $this->workflow->submitStage($client, $stage->id, $submitter);
        $this->workflow->approveStage($client, $stage->id, $approver);

        $submitterScope = $this->performance->outputVolume($submitter->fresh(), date('Y-m'))['scopes']['workflow'];
        $approverVolume = $this->performance->outputVolume($approver->fresh(), date('Y-m'));

        $this->assertSame(1.0, $submitterScope['mine']);
        $this->assertNull($approverVolume, 'the approver did not submit anything themselves, so they have nothing to show here');
    }

    /**
     * The exact scenario reported: a stage auto-submitted purely as a side
     * effect of booking a meeting, with nobody clicking a workflow button at
     * all, still credits whoever booked the meeting.
     */
    public function test_booking_a_meeting_auto_submits_its_stage_and_credits_whoever_booked_it(): void
    {
        $this->stage('meeting_scheduled', 'Sales', 1, requiresApproval: false);
        $client = $this->client();
        $booker = $this->worker('Sales');

        app(MeetingService::class)->create($client, [
            'title' => 'Kickoff', 'type' => 'Online', 'scheduled_at' => now()->addDay(),
        ], $booker);

        $progress = ClientStageProgress::where('client_id', $client->id)->firstOrFail();
        $this->assertSame($booker->id, $progress->submitted_by);

        $scope = $this->performance->outputVolume($booker->fresh(), date('Y-m'))['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    /** A stage submission blends into the same scope a Flow item already feeds — for the same client, it's still one client's worth of progress. */
    public function test_a_stage_submission_for_the_same_client_as_a_flow_item_does_not_double_count(): void
    {
        $client = $this->client();
        $stage = $this->stage('brief', 'Sales', 1, requiresApproval: false);
        $worker = $this->worker('Sales');

        $flow = \App\Models\Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $worker->id]);
        $flow->stages()->create(['name' => 'Only Stage', 'position' => 1])->users()->sync([$worker->id]);
        $flowSvc = app(\App\Services\FlowService::class);
        $item = $flowSvc->createItem($flow->refresh(), ['title' => 'Item', 'due_date' => now()->toDateString(), 'client_id' => $client->id], $worker);
        $flowSvc->claim($item->fresh(), $worker);

        $this->workflow->submitStage($client, $stage->id, $worker);

        $scope = $this->performance->outputVolume($worker->fresh(), date('Y-m'))['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine'], 'the Flow item and the stage submission are the same client\'s progress, not two');
    }

    /** A different client's stage submission is genuinely additional volume, not deduplicated away. */
    public function test_stage_submissions_for_two_different_clients_both_count(): void
    {
        $worker = $this->worker('Sales');
        $stageA = $this->stage('brief_a', 'Sales', 1, requiresApproval: false);
        $this->workflow->submitStage($this->client(), $stageA->id, $worker);
        $this->workflow->submitStage($this->client(), $stageA->id, $worker);

        $scope = $this->performance->outputVolume($worker->fresh(), date('Y-m'))['scopes']['workflow'];
        $this->assertSame(2.0, $scope['mine']);
    }

    /** Someone who only ever works the department pipeline — no Flow item at all — can still set the company-wide bar others are compared against. */
    public function test_a_stage_only_worker_with_no_flow_items_can_set_the_cohort_max(): void
    {
        $stageA = $this->stage('brief_a', 'Sales', 1, requiresApproval: false);

        // Two DIFFERENT clients — a client-access worker's count dedupes by
        // client, so this is genuinely 2 points, not the same client twice.
        $busy = $this->worker('Sales');
        $this->workflow->submitStage($this->client(), $stageA->id, $busy);
        $this->workflow->submitStage($this->client(), $stageA->id, $busy);

        $quiet = $this->worker('Sales');
        $this->workflow->submitStage($this->client(), $stageA->id, $quiet);

        $quietScope = $this->performance->outputVolume($quiet->fresh(), date('Y-m'))['scopes']['workflow'];

        // Theirs/company's highest are still tracked and shown...
        $this->assertSame(1.0, $quietScope['mine']);
        $this->assertSame(2.0, $quietScope['cohort_max']);
        // ...but Result is 100%: nothing of theirs is stalled (a department
        // pipeline submission has no open/pending state to stall), so
        // having done less than Busy doesn't reduce it.
        $this->assertSame(100.0, $quietScope['pct']);
    }

    /** No backfill job needed: a past month's submission is already correctly counted, computed live from the same rows. */
    public function test_a_past_periods_submission_is_still_counted_correctly(): void
    {
        $client = $this->client();
        $stage = $this->stage('brief', 'Sales', 1, requiresApproval: false);
        $worker = $this->worker('Sales');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-06-15 10:00:00'));
        $this->workflow->submitStage($client, $stage->id, $worker);

        $scope = $this->performance->outputVolume($worker->fresh(), '2026-06')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);

        $elsewhere = $this->performance->outputVolume($worker->fresh(), '2026-07');
        $this->assertNull($elsewhere);
    }

    /** Resubmitting the same stage after a revision request overwrites the one row rather than creating a second — no duplicate credit. */
    public function test_resubmitting_the_same_stage_does_not_double_count(): void
    {
        $client = $this->client();
        $stage = $this->stage('design', 'Design', 1, requiresApproval: true);
        $worker = $this->worker('Design');
        $reviewer = tap($this->worker('Design'))->givePermissionTo('approve-stage');

        $this->workflow->submitStage($client, $stage->id, $worker);
        $this->workflow->requestRevision($client, $stage->id, $reviewer, 'Please fix the logo size');
        $this->workflow->submitStage($client, $stage->id, $worker);

        $this->assertSame(1, ClientStageProgress::where('client_id', $client->id)->where('stage_id', $stage->id)->count());

        $scope = $this->performance->outputVolume($worker->fresh(), date('Y-m'))['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine']);
    }

    /** Batch scoring (the scoreboard's prefetch) must produce the exact same numbers as scoring one person at a time. */
    public function test_batch_scoring_matches_individual_scoring(): void
    {
        $stageA = $this->stage('brief_a', 'Sales', 1, requiresApproval: false);
        $first = $this->worker('Sales');
        $second = $this->worker('Sales');
        $this->workflow->submitStage($this->client(), $stageA->id, $first);
        $this->workflow->submitStage($this->client(), $stageA->id, $second);
        $this->workflow->submitStage($this->client(), $stageA->id, $second);

        $individually = [
            $this->performance->outputVolume($first, date('Y-m')),
            $this->performance->outputVolume($second, date('Y-m')),
        ];

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$first, $second]), date('Y-m'));

        $this->assertEquals($individually, [
            $batched->outputVolume($first, date('Y-m')),
            $batched->outputVolume($second, date('Y-m')),
        ]);
    }
}
