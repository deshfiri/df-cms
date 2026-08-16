<?php

namespace App\Services\Concerns;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * One definition of "who should hear about this".
 *
 * Six services had grown their own copy of the same recipient lookup, and they
 * had drifted: some notified whole roles without checking whether those people
 * can actually act on the thing, and most notified the person who just did the
 * work. This centralises three rules:
 *
 *   1. only active users
 *   2. only people who hold the permission that governs the action
 *   3. never the actor — you don't get told about your own work
 */
trait NotifiesStaff
{
    /**
     * Resolve recipients in a single query.
     *
     * @param  array<int,string>  $roles       Role names; missing ones are ignored.
     * @param  string|null        $permission  Narrows to holders of this permission.
     * @param  User|null          $except      The actor, who is never notified.
     * @return Collection<int,User>
     */
    protected function staffRecipients(array $roles, ?string $permission = null, ?User $except = null): Collection
    {
        // Spatie's role() scope throws when a role name doesn't exist at all,
        // rather than simply matching nobody — so resolve real names first. A
        // mistyped or removed role must never break the action that triggered
        // the notification.
        $existingRoles = Role::whereIn('name', $roles)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        if (empty($existingRoles)) {
            return collect();
        }

        // permission() throws the same way role() does. If the permission has
        // been renamed or removed we fall back to role-only rather than
        // returning nobody: over-notifying is visible and recoverable, silently
        // dropping "there is work waiting for you" is not.
        $applyPermission = $permission !== null
            && Permission::where('name', $permission)->where('guard_name', 'web')->exists();

        // Both filters are applied in SQL. Previously some callers fetched every
        // member of a role and then ran hasPermissionTo() per user in PHP, which
        // lazily loaded that user's roles and permissions one model at a time.
        return User::query()
            ->role($existingRoles)
            ->when($applyPermission, fn ($q) => $q->permission($permission))
            ->where('is_active', true)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->get();
    }

    /** Resolve recipients and notify them, skipping the send entirely if nobody qualifies. */
    protected function notifyStaff(array $roles, Notification $notification, ?string $permission = null, ?User $except = null): void
    {
        $recipients = $this->staffRecipients($roles, $permission, $except);

        if ($recipients->isNotEmpty()) {
            NotificationFacade::send($recipients, $notification);
        }
    }
}
