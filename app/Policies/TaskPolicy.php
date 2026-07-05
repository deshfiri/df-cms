<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['view tasks', 'manage tasks']);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasAnyPermission(['view tasks', 'manage tasks']);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage tasks');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->hasPermissionTo('manage tasks');
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasPermissionTo('manage tasks');
    }
}
