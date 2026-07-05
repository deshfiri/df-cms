<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        private readonly ActivityLogService    $activityLog,
        private readonly ChangeApprovalService $changeApproval,
    ) {}

    public function update(User $user, array $data): User
    {
        $updateData = [
            'name'      => $data['name'],
            'email'     => $data['email'],
            'is_active' => $data['is_active'] ?? $user->is_active,
        ];
        if (!empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        $roles = $data['roles'];

        $oldSnapshot = $user->only(['name', 'email', 'is_active']) + ['roles' => $user->roles->pluck('name')->all()];
        $newSnapshot = $updateData + ['roles' => $roles];

        $this->changeApproval->guard(User::class, $user->id, $oldSnapshot, $newSnapshot, Auth::user());

        return DB::transaction(function () use ($user, $updateData, $roles) {
            $old = $user->only(['name', 'email', 'is_active']);
            $user->update($updateData);
            $user->syncRoles($roles);
            $this->activityLog->log('User', 'Updated', null, $old, $user->only(['name', 'email', 'is_active']));

            return $user->fresh();
        });
    }
}
