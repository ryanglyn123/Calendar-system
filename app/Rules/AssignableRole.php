<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * super_admin bypasses every permission check (Gate::before), so only a
 * super admin may hand it out — through approval, invitation or user edit.
 */
class AssignableRole implements ValidationRule
{
    public function __construct(
        private readonly User $actor,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === 'super_admin' && ! $this->actor->hasRole('super_admin')) {
            $fail('Only a super admin can grant the super_admin role.');
        }
    }
}
