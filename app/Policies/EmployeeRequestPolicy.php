<?php

namespace App\Policies;

use App\Models\EmployeeRequest;
use App\Models\User;

/**
 * Staff requests.
 *
 * Three seats, each granted to roles in Settings → Roles:
 *  - "view requests"   — open the page and follow your own requests;
 *  - "create requests" — file a new one;
 *  - "manage requests" — see everyone's, approve or reject (implies both).
 *
 * Checked with can(), never hasPermissionTo(): the latter throws when a
 * permission row has not been seeded, which would turn a missing grant into
 * a 500 instead of a plain "no".
 */
class EmployeeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view requests', 'create requests', 'manage requests']);
    }

    public function view(User $user, EmployeeRequest $employeeRequest): bool
    {
        if ($user->can('manage requests')) {
            return true;
        }

        return $employeeRequest->requested_by === $user->id && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->canAny(['create requests', 'manage requests']);
    }

    public function respond(User $user, EmployeeRequest $employeeRequest): bool
    {
        return $user->can('manage requests');
    }

    public function delete(User $user, EmployeeRequest $employeeRequest): bool
    {
        if ($user->can('manage requests')) {
            return true;
        }

        // Withdrawing your own pending request is part of being allowed to make one.
        return $employeeRequest->requested_by === $user->id
            && $employeeRequest->status === EmployeeRequest::STATUS_PENDING
            && $this->create($user);
    }
}
