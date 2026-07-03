<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage') || $user->can('users.approve');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('users.manage');
    }

    public function approve(User $user, User $target): bool
    {
        return $user->can('users.approve');
    }

    public function suspend(User $user, User $target): bool
    {
        return $user->can('users.manage');
    }

    public function delete(User $user, User $target): bool
    {
        return $user->can('users.manage');
    }
}
