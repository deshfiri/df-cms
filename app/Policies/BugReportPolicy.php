<?php

namespace App\Policies;

use App\Models\BugReport;
use App\Models\User;

/**
 * Bug/issue reports.
 *
 * Reporting something broken is part of having a login, so every signed-in
 * member of staff can open the page, file a report and follow their own.
 * The list is scoped to the person in BugReportController.
 *
 * One permission governs the other side of it:
 *  - "manage bug reports" — see everyone's, resolve or close.
 *
 * Checked with can(), never hasPermissionTo(): the latter throws when a
 * permission row has not been seeded, which would turn a missing grant into
 * a 500 instead of a plain "no".
 */
class BugReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BugReport $bugReport): bool
    {
        return $user->can('manage bug reports') || $bugReport->reported_by === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function respond(User $user, BugReport $bugReport): bool
    {
        return $user->can('manage bug reports');
    }

    public function delete(User $user, BugReport $bugReport): bool
    {
        if ($user->can('manage bug reports')) {
            return true;
        }

        // Withdrawing your own report, while it hasn't been looked at yet.
        return $bugReport->reported_by === $user->id
            && $bugReport->status === BugReport::STATUS_OPEN;
    }
}
