<?php

namespace App\Policies;

use App\Models\Refund;
use App\Models\User;

/**
 * Who may do what with a refund.
 *
 *   request refunds   ask for one; cancel your own before paying out starts
 *   approve refunds   review, approve, reject, cancel — never on your own request
 *   process refunds   pay out an approved refund and confirm it with a reference
 *
 * The "never your own request" rule is also enforced in RefundService, because
 * Gate::before lets a Super Admin past any policy called through the Gate.
 */
class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view payments', 'manage payments', 'request refunds', 'approve refunds', 'process refunds']);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->viewAny($user);
    }

    public function request(User $user): bool
    {
        return $user->can('request refunds');
    }

    public function review(User $user, Refund $refund): bool
    {
        return $refund->status === Refund::STATUS_REQUESTED && $this->decides($user, $refund);
    }

    public function approve(User $user, Refund $refund): bool
    {
        return in_array($refund->status, [Refund::STATUS_REQUESTED, Refund::STATUS_UNDER_REVIEW], true) && $this->decides($user, $refund);
    }

    public function reject(User $user, Refund $refund): bool
    {
        return $this->approve($user, $refund);
    }

    public function process(User $user, Refund $refund): bool
    {
        return $refund->status === Refund::STATUS_APPROVED && $user->can('process refunds');
    }

    public function complete(User $user, Refund $refund): bool
    {
        return $refund->status === Refund::STATUS_PROCESSING && $user->can('process refunds');
    }

    /** Before any money moves: by whoever asked, or by an approver. */
    public function cancel(User $user, Refund $refund): bool
    {
        if (!in_array($refund->status, [Refund::STATUS_REQUESTED, Refund::STATUS_UNDER_REVIEW, Refund::STATUS_APPROVED], true)) {
            return false;
        }

        $own = (int) $refund->requested_by === (int) $user->id;

        return ($own && $user->can('request refunds') && $refund->status !== Refund::STATUS_APPROVED)
            || $user->can('approve refunds');
    }

    private function decides(User $user, Refund $refund): bool
    {
        return (int) $refund->requested_by !== (int) $user->id && $user->can('approve refunds');
    }
}
