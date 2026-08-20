<?php

namespace App\Rules;

use App\Models\User;
use App\Services\TaskDelegationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Keeps a delegated task inside the assigner's reach.
 *
 * The form only offers the right people, but the check has to live server-side
 * too — a stage worker must not be able to post any user id they like.
 */
class AssignableUser implements ValidationRule
{
    public function __construct(
        private readonly ?User $actor,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $this->actor === null) {
            return;
        }

        if (!app(TaskDelegationService::class)->mayAssignTo($this->actor, (int) $value)) {
            $fail('You can only assign this to someone on the next stage of your workflow.');
        }
    }
}
