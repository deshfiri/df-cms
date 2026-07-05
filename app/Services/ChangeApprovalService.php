<?php

namespace App\Services;

use App\Exceptions\ChangeRequiresApprovalException;
use App\Models\PendingChange;
use App\Models\User;
use App\Notifications\ChangeAwaitingApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class ChangeApprovalService
{
    private const APPROVER_ROLES = ['Super Admin', 'Manager'];

    // Deliberately does NOT include 'password': it only ever holds a one-way
    // bcrypt hash by the time it reaches here, this queue is only ever visible
    // to Super Admin/Manager (who already have full User table access anyway),
    // and redacting it would silently drop the password change when a pending
    // change is later approved and replayed.
    private const SENSITIVE_KEYS = ['remember_token', 'api_token'];

    public function isPrivileged(User $user): bool
    {
        return $user->hasRole(self::APPROVER_ROLES);
    }

    /**
     * No-op for a Super Admin/Manager (their edit proceeds immediately).
     * Otherwise, records (or amends) a pending proposal for this exact record,
     * notifies the approvers, and throws — the pending row is fully committed
     * in its own transaction before the throw, so callers must invoke this
     * BEFORE opening their own DB::transaction() for the actual mutation;
     * nesting it inside that transaction would roll the pending row back too
     * the moment this throws.
     *
     * @throws ChangeRequiresApprovalException
     */
    public function guard(string $modelClass, int $modelId, array $oldValues, array $newValues, User $actor): void
    {
        if ($this->isPrivileged($actor)) {
            return;
        }

        DB::transaction(function () use ($modelClass, $modelId, $oldValues, $newValues, $actor) {
            $pending = PendingChange::where('model_type', $modelClass)
                ->where('model_id', $modelId)
                ->pending()
                ->lockForUpdate()
                ->first();

            if ($pending) {
                $pending->update([
                    'new_values'   => $this->redact($newValues),
                    'requested_by' => $actor->id,
                ]);
            } else {
                $pending = PendingChange::create([
                    'model_type'   => $modelClass,
                    'model_id'     => $modelId,
                    'old_values'   => $this->redact($oldValues),
                    'new_values'   => $this->redact($newValues),
                    'requested_by' => $actor->id,
                    'status'       => PendingChange::STATUS_PENDING,
                ]);
            }

            $this->notifyApprovers($pending);
        });

        throw new ChangeRequiresApprovalException(
            'Your change has been submitted for Super Admin / Manager approval and has not been applied yet.'
        );
    }

    private function notifyApprovers(PendingChange $pending): void
    {
        // User::role() throws if ANY given role name doesn't exist at all
        // (rather than just finding nobody) — filter to roles that actually
        // exist first, so a missing role can never blow up this transaction.
        $existingRoles = Role::whereIn('name', self::APPROVER_ROLES)->pluck('name')->all();
        if (empty($existingRoles)) {
            return;
        }

        $approvers = User::role($existingRoles)->where('is_active', true)->get();
        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new ChangeAwaitingApproval($pending));
    }

    private function redact(array $values): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            unset($values[$key]);
        }

        return $values;
    }
}
