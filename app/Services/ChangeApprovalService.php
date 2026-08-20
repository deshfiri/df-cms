<?php

namespace App\Services;

use App\Exceptions\ChangeRequiresApprovalException;
use App\Models\PendingChange;
use App\Models\User;
use App\Notifications\ChangeAwaitingApproval;
use App\Services\Concerns\NotifiesStaff;
use Illuminate\Support\Facades\DB;

class ChangeApprovalService
{
    use NotifiesStaff;

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
     * Guard an edit only when it actually touches something worth reviewing.
     *
     * Applying four-eyes review to every field of every record turned routine
     * work into a two-step process — a salesperson could not correct a phone
     * number without a manager signing it off. Review is now reserved for the
     * fields where a wrong or quiet change matters: who owns a client, whether
     * it is still active, and what it is categorised as.
     *
     * @param  array<int,string>  $watched  Fields that require approval.
     *
     * @throws ChangeRequiresApprovalException
     */
    public function guardFields(
        string $modelClass,
        int $modelId,
        array $oldValues,
        array $newValues,
        User $actor,
        array $watched,
    ): void {
        $touched = array_filter(
            $watched,
            fn (string $field) => array_key_exists($field, $newValues)
                && ($oldValues[$field] ?? null) != $newValues[$field]
        );

        if (!$touched) {
            return;
        }

        // The whole edit waits, not just the sensitive field: approving replays
        // the payload as one update, and splitting it would apply half an edit.
        $this->guard($modelClass, $modelId, $oldValues, $newValues, $actor);
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
        // Approvers minus whoever requested the change — nobody needs a prompt
        // to approve their own edit.
        $requester = $pending->requested_by ? User::find($pending->requested_by) : null;

        $this->notifyStaff(
            self::APPROVER_ROLES,
            new ChangeAwaitingApproval($pending),
            except: $requester,
        );
    }

    private function redact(array $values): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            unset($values[$key]);
        }

        return $values;
    }
}
