<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Division chat. A plain division comparison on purpose - no permission
 * checks here, because Gate::before would let super admins into every
 * division's private room.
 */
Broadcast::channel('division.{divisionId}.chat', function (User $user, int $divisionId) {
    return $user->isActive() && (int) $user->division_id === $divisionId;
});
