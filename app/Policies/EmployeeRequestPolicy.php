<?php

namespace App\Policies;

use App\Models\EmployeeRequest;
use App\Models\User;

class EmployeeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EmployeeRequest $employeeRequest): bool
    {
        return $employeeRequest->requested_by === $user->id || $user->hasPermissionTo('manage requests');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function respond(User $user, EmployeeRequest $employeeRequest): bool
    {
        return $user->hasPermissionTo('manage requests');
    }

    public function delete(User $user, EmployeeRequest $employeeRequest): bool
    {
        if ($user->hasPermissionTo('manage requests')) {
            return true;
        }

        return $employeeRequest->requested_by === $user->id && $employeeRequest->status === EmployeeRequest::STATUS_PENDING;
    }
}
