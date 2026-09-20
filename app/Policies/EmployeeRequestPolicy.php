<?php

namespace App\Policies;

use App\Models\EmployeeRequest;
use App\Models\User;

/**
 * Staff requests.
 *
 * Asking the company for something is part of having a login, so every signed-in
 * member of staff can open the page, file a request and follow or withdraw their
 * own. The list is scoped to the person in EmployeeRequestController.
 *
 * One permission governs the other side of it:
 *  - "manage requests" — see everyone's, approve or reject.
 *
 * It used to take 'view requests' and 'create requests' as well. They were
 * granted to every role anyway, and anyone who missed them — a new or custom
 * role — silently lost the menu item with no way to ask for anything.
 *
 * Checked with can(), never hasPermissionTo(): the latter throws when a
 * permission row has not been seeded, which would turn a missing grant into
 * a 500 instead of a plain "no".
 */
class EmployeeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EmployeeRequest $employeeRequest): bool
    {
        return $user->can('manage requests') || $employeeRequest->requested_by === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
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

        // Withdrawing your own request, while it is still waiting.
        return $employeeRequest->requested_by === $user->id
            && $employeeRequest->status === EmployeeRequest::STATUS_PENDING;
    }
}
