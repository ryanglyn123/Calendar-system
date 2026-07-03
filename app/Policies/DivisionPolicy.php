<?php

namespace App\Policies;

use App\Models\Division;
use App\Models\User;

class DivisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('divisions.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('divisions.manage');
    }

    public function update(User $user, Division $division): bool
    {
        return $user->can('divisions.manage');
    }

    public function delete(User $user, Division $division): bool
    {
        return $user->can('divisions.manage');
    }
}
