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
 * A request now names who it goes to (EmployeeRequest::recipients) instead of
 * being answerable by anyone holding a permission — filing one to one manager
 * must not make it visible to, or actionable by, every other manager too. So
 * the only people who may see or act on a given request are whoever filed it
 * and whoever it was actually sent to; "manage requests" no longer grants
 * blanket access to every request (Super Admin still sees everything, via the
 * unconditional Gate::before in AppServiceProvider — that is a separate rule
 * from anything checked here).
 *
 * It used to take 'view requests' and 'create requests' as well. They were
 * granted to every role anyway, and anyone who missed them — a new or custom
 * role — silently lost the menu item with no way to ask for anything.
 */
class EmployeeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EmployeeRequest $employeeRequest): bool
    {
        return $employeeRequest->requested_by === $user->id
            || $employeeRequest->recipients->contains($user->id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Whoever it was sent to may act on it — being named a recipient is itself the authorization. */
    public function respond(User $user, EmployeeRequest $employeeRequest): bool
    {
        return $employeeRequest->recipients->contains($user->id);
    }

    /** Same authorization as respond() — forwarding is the other thing a recipient may do instead of answering. */
    public function forward(User $user, EmployeeRequest $employeeRequest): bool
    {
        return $employeeRequest->recipients->contains($user->id);
    }

    /** Withdrawing your own request, while it is still waiting. Not a recipient's call. */
    public function delete(User $user, EmployeeRequest $employeeRequest): bool
    {
        return $employeeRequest->requested_by === $user->id
            && $employeeRequest->status === EmployeeRequest::STATUS_PENDING;
    }
}
