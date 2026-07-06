<?php

namespace App\Services;

use App\Exceptions\WorkflowStageException;
use App\Models\Client;
use App\Models\ClientStageProgress;
use App\Models\Payment;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\StageAwaitingApproval;
use App\Notifications\StageReadyForDepartment;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class WorkflowService
{
    public function __construct(
        private readonly WorkflowRepositoryInterface $workflowRepo,
        private readonly ActivityLogService          $activityLog,
    ) {}

    /**
     * Whether $user may act (submit/approve) on $stage without being blocked
     * by department ownership. Super Admin is handled separately via Gate::before.
     */
    public function userOwnsStage(User $user, WorkflowStage $stage): bool
    {
        if (!$stage->department) {
            return true;
        }

        return $user->hasRole($stage->department) || $user->hasRole('Manager') || $user->hasPermissionTo('manage-workflow');
    }

    /**
     * A stage is locked until every earlier active stage is Approved, and
     * also while the client's payment status blocks further progress (see
     * getPaymentBlockReason()), or while the client is Terminated.
     */
    public function isLocked(int $clientId, WorkflowStage $stage): bool
    {
        if ($this->clientIsTerminated($clientId)) {
            return true;
        }

        if ($this->getPaymentBlockReason($clientId, $stage) !== null) {
            return true;
        }

        $before = $this->workflowRepo->stagesBefore($stage);
        if ($before->isEmpty()) {
            return false;
        }

        $approvedCount = ClientStageProgress::where('client_id', $clientId)
            ->whereIn('stage_id', $before->pluck('id'))
            ->where('status', ClientStageProgress::STATUS_APPROVED)
            ->count();

        return $approvedCount < $before->count();
    }

    /**
     * Payment gate: a client with no payment on record (or the latest one
     * marked Unpaid) cannot progress the workflow at all. A client who has
     * only paid Partially can proceed normally through everything before the
     * Product segment, but Product Sourcing onward stays locked until the
     * latest payment is marked Paid in full. Returns null when unblocked.
     */
    public function getPaymentBlockReason(int $clientId, WorkflowStage $stage): ?string
    {
        $paymentStatus = Payment::where('client_id', $clientId)->latest()->value('status');

        if (empty($paymentStatus) || $paymentStatus === 'Unpaid') {
            return 'This client has no payment on record — the workflow cannot proceed until at least a partial payment is made.';
        }

        if ($paymentStatus === 'Partial') {
            $productStage = WorkflowStage::where('code', 'product_sourcing')->first();
            if ($productStage && $stage->sort_order >= $productStage->sort_order) {
                return 'This client has only paid partially — Product stages onward stay locked until payment is completed in full.';
            }
        }

        return null;
    }

    private function clientIsTerminated(int $clientId): bool
    {
        return Client::where('id', $clientId)->value('client_status') === 'Terminated';
    }

    /**
     * Full ordered timeline for a client: each stage annotated with its
     * progress record plus computed completed/current/locked/overdue state.
     */
    public function getTimeline(Client $client): array
    {
        $stages   = WorkflowStage::active()->get();
        $progress = $this->workflowRepo->getClientProgress($client->id);

        $reachedCurrent = false;

        return $stages->map(function (WorkflowStage $stage) use ($client, $progress, &$reachedCurrent) {
            $record      = $progress->get($stage->id);
            $status      = $record->status ?? ClientStageProgress::STATUS_PENDING;
            $terminated  = $client->client_status === 'Terminated';
            $paymentLock = $this->getPaymentBlockReason($client->id, $stage);
            $locked      = $terminated || $paymentLock !== null || $this->isLocked($client->id, $stage);

            $isCurrent = false;
            if (!$locked && $status !== ClientStageProgress::STATUS_APPROVED && !$reachedCurrent) {
                $isCurrent      = true;
                $reachedCurrent = true;
            }

            $overdue = !$locked
                && $status !== ClientStageProgress::STATUS_APPROVED
                && $record
                && $record->updated_at
                && $record->updated_at->lt(now()->subDays(7));

            return [
                'stage'         => $stage,
                'progress'      => $record,
                'status'        => $status,
                'locked'        => $locked,
                'terminated'    => $terminated,
                'payment_lock'  => $paymentLock,
                'current'       => $isCurrent,
                'overdue'       => $overdue,
            ];
        })->all();
    }

    /**
     * Sales/Design/etc. submit their work for a stage. Stages that don't
     * require formal approval are auto-approved on submit.
     */
    public function submitStage(Client $client, int $stageId, User $user, ?string $remarks = null): ClientStageProgress
    {
        if ($client->client_status === 'Terminated') {
            throw new WorkflowStageException('This client has been terminated — the workflow is locked.');
        }

        $stage = $this->workflowRepo->findStage($stageId);

        if (!$user->hasRole('Super Admin') && !$this->userOwnsStage($user, $stage)) {
            throw new WorkflowStageException("Only the {$stage->department} team can work on this stage.");
        }

        // Only Super Admin silently bypasses the lock here. Everyone else —
        // including Manager and other manage-workflow holders — must use the
        // explicit, separately-logged toggleStage() admin override instead.
        if (!$user->hasRole('Super Admin')) {
            if ($reason = $this->getPaymentBlockReason($client->id, $stage)) {
                throw new WorkflowStageException($reason);
            }
            if ($this->isLocked($client->id, $stage)) {
                throw new WorkflowStageException('This stage is locked until the previous stage is approved.');
            }
        }

        return DB::transaction(function () use ($client, $stage, $user, $remarks) {
            $progress = $this->workflowRepo->getOrCreateProgress($client->id, $stage->id);

            $autoApprove = !$stage->requires_approval;

            $progress->update([
                'status'        => $autoApprove ? ClientStageProgress::STATUS_APPROVED : ClientStageProgress::STATUS_SUBMITTED,
                'submitted_by'  => $user->id,
                'submitted_at'  => now(),
                'remarks'       => $remarks,
                'rejection_reason' => null,
                'is_completed'  => $autoApprove,
                'completed_at'  => $autoApprove ? now() : null,
                'completed_by'  => $autoApprove ? $user->id : null,
            ]);

            $this->activityLog->log('Workflow', $autoApprove ? 'Stage Approved' : 'Stage Submitted', $client->id, null, ['stage' => $stage->name]);

            if ($autoApprove) {
                $this->notifyNextDepartment($client, $stage, $user);
            } else {
                $this->notifyApprovers($client, $stage, $user);
            }

            return $progress;
        });
    }

    /**
     * Design/Website/etc. lead or Manager approves a submitted stage,
     * unlocking the next one and notifying its department.
     */
    public function approveStage(Client $client, int $stageId, User $user): ClientStageProgress
    {
        if ($client->client_status === 'Terminated') {
            throw new WorkflowStageException('This client has been terminated — the workflow is locked.');
        }

        $stage = $this->workflowRepo->findStage($stageId);

        if (!$user->hasRole('Super Admin') && !$user->hasPermissionTo('approve-stage')) {
            throw new WorkflowStageException('You do not have permission to approve this stage.');
        }

        if (!$user->hasRole('Super Admin') && !$this->userOwnsStage($user, $stage)) {
            throw new WorkflowStageException("Only the {$stage->department} team can approve this stage.");
        }

        // Defense in depth: if an admin override reverted an earlier stage
        // after this one was submitted, the chain is broken again and this
        // approval must not go through until the earlier stage is re-approved.
        if (!$user->hasRole('Super Admin')) {
            if ($reason = $this->getPaymentBlockReason($client->id, $stage)) {
                throw new WorkflowStageException($reason);
            }
            if ($this->isLocked($client->id, $stage)) {
                throw new WorkflowStageException('This stage is locked until the previous stage is approved.');
            }
        }

        return DB::transaction(function () use ($client, $stage, $user) {
            $progress = $this->workflowRepo->getOrCreateProgress($client->id, $stage->id);

            if (!in_array($progress->status, [ClientStageProgress::STATUS_SUBMITTED, ClientStageProgress::STATUS_NEED_REVISION], true)) {
                throw new WorkflowStageException('Only a submitted stage can be approved.');
            }

            $progress->update([
                'status'       => ClientStageProgress::STATUS_APPROVED,
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $user->id,
            ]);

            $this->activityLog->log('Workflow', 'Stage Approved', $client->id, null, ['stage' => $stage->name]);

            $this->notifyNextDepartment($client, $stage, $user);

            return $progress;
        });
    }

    /**
     * Send a submitted stage back for revision.
     */
    public function requestRevision(Client $client, int $stageId, User $user, string $reason): ClientStageProgress
    {
        if ($client->client_status === 'Terminated') {
            throw new WorkflowStageException('This client has been terminated — the workflow is locked.');
        }

        $stage = $this->workflowRepo->findStage($stageId);

        if (!$user->hasRole('Super Admin') && !$user->hasPermissionTo('approve-stage')) {
            throw new WorkflowStageException('You do not have permission to review this stage.');
        }

        if (!$user->hasRole('Super Admin') && !$this->userOwnsStage($user, $stage)) {
            throw new WorkflowStageException("Only the {$stage->department} team can send this stage back for revision.");
        }

        return DB::transaction(function () use ($client, $stage, $user, $reason) {
            $progress = $this->workflowRepo->getOrCreateProgress($client->id, $stage->id);

            $progress->update([
                'status'            => ClientStageProgress::STATUS_NEED_REVISION,
                'is_completed'      => false,
                'completed_at'      => null,
                'completed_by'      => null,
                'rejection_reason'  => $reason,
            ]);

            $this->activityLog->log('Workflow', 'Stage Needs Revision', $client->id, null, ['stage' => $stage->name, 'reason' => $reason]);

            return $progress;
        });
    }

    /**
     * Direct admin override — bypasses locking and department ownership.
     * Only reachable by users with manage-workflow (Super Admin already
     * bypasses every gate).
     */
    public function toggleStage(int $clientId, int $stageId, bool $completed): array
    {
        if ($this->clientIsTerminated($clientId)) {
            throw new WorkflowStageException('This client has been terminated — the workflow is locked.');
        }

        return DB::transaction(function () use ($clientId, $stageId, $completed) {
            $progress = $this->workflowRepo->toggleStage($clientId, $stageId, $completed, Auth::id());
            $progress->update([
                'status' => $completed ? ClientStageProgress::STATUS_APPROVED : ClientStageProgress::STATUS_PENDING,
            ]);
            $percent = $this->workflowRepo->calculateProgress($clientId);

            $this->activityLog->log(
                'Workflow',
                $completed ? 'Stage Completed (Admin Override)' : 'Stage Uncompleted (Admin Override)',
                $clientId,
                null,
                ['stage_id' => $stageId, 'completed' => $completed]
            );

            return ['progress' => $percent, 'progress_record' => $progress];
        });
    }

    public function createStage(array $data): WorkflowStage
    {
        $maxOrder      = WorkflowStage::max('sort_order') ?? 0;
        $data['sort_order'] ??= $maxOrder + 1;

        $stage = $this->workflowRepo->createStage($data);

        // Add this stage to all existing clients
        Client::pluck('id')->each(function ($clientId) use ($stage) {
            $this->workflowRepo->toggleStage($clientId, $stage->id, false, Auth::id());
        });

        $this->activityLog->log('Workflow', 'Stage Created', null, null, $data);

        return $stage;
    }

    public function updateStage(WorkflowStage $stage, array $data): WorkflowStage
    {
        $updated = $this->workflowRepo->updateStage($stage, $data);
        $this->activityLog->log('Workflow', 'Stage Updated', null, $stage->toArray(), $data);

        return $updated;
    }

    /**
     * Permanently removes a stage and every client's progress record for it.
     * This is destructive and not reversible — history for this stage is gone.
     */
    public function deleteStage(WorkflowStage $stage): void
    {
        DB::transaction(function () use ($stage) {
            $affectedClientIds = ClientStageProgress::where('stage_id', $stage->id)->pluck('client_id');

            $this->activityLog->log(
                'Workflow',
                'Stage Deleted (progress history wiped)',
                null,
                $stage->toArray(),
                ['affected_clients' => $affectedClientIds->count()]
            );

            ClientStageProgress::where('stage_id', $stage->id)->delete();
            $stage->forceDelete();
        });
    }

    /**
     * Merges $source into $target: for every client where $source is Approved,
     * $target is marked Approved too (never downgrading a $target that's
     * already Approved). $source is then retired (soft-deleted) so its
     * history and audit trail remain intact but it drops out of the pipeline.
     */
    public function mergeStage(WorkflowStage $source, WorkflowStage $target): void
    {
        if ($source->id === $target->id) {
            throw new WorkflowStageException('Cannot merge a stage into itself.');
        }

        DB::transaction(function () use ($source, $target) {
            $sourceProgress = ClientStageProgress::where('stage_id', $source->id)
                ->where('status', ClientStageProgress::STATUS_APPROVED)
                ->get();

            foreach ($sourceProgress as $progress) {
                $targetProgress = $this->workflowRepo->getOrCreateProgress($progress->client_id, $target->id);

                if ($targetProgress->status === ClientStageProgress::STATUS_APPROVED) {
                    continue; // target already ahead, don't downgrade it
                }

                $targetProgress->update([
                    'status'       => ClientStageProgress::STATUS_APPROVED,
                    'is_completed' => true,
                    'completed_at' => $progress->completed_at ?? now(),
                    'completed_by' => $progress->completed_by,
                ]);

                $this->activityLog->log(
                    'Workflow',
                    "Stage Merged: {$source->name} → {$target->name}",
                    $progress->client_id,
                    null,
                    ['from_stage' => $source->name, 'to_stage' => $target->name]
                );
            }

            $source->update(['status' => false]);
            $source->delete(); // soft delete — retains row + history for audit
        });
    }

    /**
     * System-triggered stage transitions driven by a business event elsewhere
     * in the app (e.g. a meeting being completed/cancelled), rather than a
     * human clicking Submit/Approve/Reject on the timeline. These deliberately
     * skip the department-ownership and approve-stage/submit-stage permission
     * checks used by the manual methods above — authorization already happened
     * at the point the triggering action was allowed (e.g. "can this user mark
     * this meeting completed"), and re-checking workflow-specific permissions
     * against the acting user here would incorrectly couple two unrelated
     * authorization concerns.
     */
    public function systemApproveStageByCode(Client $client, string $stageCode, ?User $actor, string $reason): ?ClientStageProgress
    {
        $stage = WorkflowStage::where('code', $stageCode)->first();
        if (!$stage) {
            return null;
        }

        if ($blockReason = $this->getPaymentBlockReason($client->id, $stage)) {
            $progress = $this->workflowRepo->getOrCreateProgress($client->id, $stage->id);
            $this->activityLog->log('Workflow', "Approval Blocked ({$reason}): {$blockReason}", $client->id, null, ['stage' => $stage->name]);

            return $progress;
        }

        return DB::transaction(function () use ($client, $stage, $actor, $reason) {
            $progress = $this->workflowRepo->getOrCreateProgress($client->id, $stage->id);

            $progress->update([
                'status'       => ClientStageProgress::STATUS_APPROVED,
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $actor?->id,
            ]);

            $this->activityLog->log('Workflow', "Stage Approved ({$reason})", $client->id, null, ['stage' => $stage->name]);
            $this->notifyNextDepartment($client, $stage, $actor);

            return $progress;
        });
    }

    public function systemRevisionByCode(Client $client, string $stageCode, ?User $actor, string $reason): ?ClientStageProgress
    {
        $stage = WorkflowStage::where('code', $stageCode)->first();
        if (!$stage) {
            return null;
        }

        return DB::transaction(function () use ($client, $stage, $reason) {
            $progress = $this->workflowRepo->getOrCreateProgress($client->id, $stage->id);

            // Never downgrade a stage that's already been approved through
            // this or another path (e.g. a meeting cancelled long after the
            // stage was already approved shouldn't silently re-lock things).
            if ($progress->status === ClientStageProgress::STATUS_APPROVED) {
                return $progress;
            }

            $progress->update([
                'status'           => ClientStageProgress::STATUS_NEED_REVISION,
                'is_completed'     => false,
                'completed_at'     => null,
                'completed_by'     => null,
                'rejection_reason' => $reason,
            ]);

            $this->activityLog->log('Workflow', "Stage Needs Revision ({$reason})", $client->id, null, ['stage' => $stage->name]);

            return $progress;
        });
    }

    public function systemSubmitByCode(Client $client, string $stageCode, ?User $actor, string $reason): ?ClientStageProgress
    {
        $stage = WorkflowStage::where('code', $stageCode)->first();
        if (!$stage) {
            return null;
        }

        if ($blockReason = $this->getPaymentBlockReason($client->id, $stage)) {
            $progress = $this->workflowRepo->getOrCreateProgress($client->id, $stage->id);
            $this->activityLog->log('Workflow', "Submission Blocked ({$reason}): {$blockReason}", $client->id, null, ['stage' => $stage->name]);

            return $progress;
        }

        return DB::transaction(function () use ($client, $stage, $actor, $reason) {
            $progress = $this->workflowRepo->getOrCreateProgress($client->id, $stage->id);

            // Don't downgrade an already-submitted/approved stage just because
            // the triggering event (e.g. booking a meeting) fired again.
            if (in_array($progress->status, [ClientStageProgress::STATUS_SUBMITTED, ClientStageProgress::STATUS_APPROVED], true)) {
                return $progress;
            }

            $progress->update([
                'status'           => ClientStageProgress::STATUS_SUBMITTED,
                'submitted_by'     => $actor?->id,
                'submitted_at'     => now(),
                'rejection_reason' => null,
            ]);

            $this->activityLog->log('Workflow', "Stage Submitted ({$reason})", $client->id, null, ['stage' => $stage->name]);

            return $progress;
        });
    }

    /**
     * When a stage that requires_approval is submitted (not auto-approved),
     * the department that owns approving THIS stage must be told it's
     * waiting on them — otherwise it silently sits in "Submitted" until
     * someone happens to open the client's timeline.
     */
    private function notifyApprovers(Client $client, WorkflowStage $stage, User $actor): void
    {
        if (!$stage->department || !Role::where('name', $stage->department)->where('guard_name', 'web')->exists()) {
            return;
        }

        $recipients = User::role($stage->department)
            ->where('is_active', true)
            ->where('id', '!=', $actor->id)
            ->get()
            ->filter(fn (User $u) => $u->hasPermissionTo('approve-stage'))
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new StageAwaitingApproval($client, $stage));
    }

    private function notifyNextDepartment(Client $client, WorkflowStage $approvedStage, ?User $actor = null): void
    {
        $next = $this->workflowRepo->nextActiveStage($approvedStage);
        if (!$next || !$next->department) {
            return;
        }

        // Ensure the next stage has a progress row the moment it becomes
        // reachable, so the owning department's dashboard ("Awaiting Your
        // Team") reflects this client immediately — not just once someone
        // happens to open the stage and lazily create the row.
        $this->workflowRepo->getOrCreateProgress($client->id, $next->id);

        // A stage's department is a free-text label set on the Workflow Stages
        // admin page and doesn't have to match an existing role (e.g. "Admin"
        // isn't a role — only "Super Admin" is). User::role() throws for an
        // unknown role name instead of just finding nobody, so check first —
        // a mistyped/placeholder department must never block the stage
        // completion that got us here.
        if (!Role::where('name', $next->department)->where('guard_name', 'web')->exists()) {
            return;
        }

        // Several consecutive stages can share the same department (e.g. Sales
        // owns deal_completed, meeting_scheduled, and agreement_signed back to
        // back), so the person who just acted is often also a member of the
        // next stage's department — don't notify them about their own work.
        $recipients = User::role($next->department)
            ->where('is_active', true)
            ->when($actor, fn ($q) => $q->where('id', '!=', $actor->id))
            ->get();
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new StageReadyForDepartment($client, $approvedStage, $next));
    }
}
