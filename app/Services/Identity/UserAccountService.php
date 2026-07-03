<?php

namespace App\Services\Identity;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\UserApprovedNotification;
use Illuminate\Support\Facades\DB;

class UserAccountService
{
    /**
     * Approve a pending registration, assigning the division and role the
     * reviewing admin selected.
     */
    public function approve(User $user, int $divisionId, string $role, User $approvedBy): User
    {
        return DB::transaction(function () use ($user, $divisionId, $role, $approvedBy) {
            $user->forceFill([
                'status' => UserStatus::Active,
                'division_id' => $divisionId,
                'approved_at' => now(),
                'approved_by' => $approvedBy->id,
            ])->save();

            $user->syncRoles([$role]);

            activity()
                ->causedBy($approvedBy)
                ->performedOn($user)
                ->event('approved')
                ->log("Approved {$user->email}");

            $user->notify(new UserApprovedNotification($approvedBy));

            return $user;
        });
    }

    /**
     * Reject (delete) a pending registration.
     */
    public function reject(User $user, User $rejectedBy): void
    {
        activity()
            ->causedBy($rejectedBy)
            ->performedOn($user)
            ->event('rejected')
            ->log("Rejected registration for {$user->email}");

        $user->forceDelete();
    }

    public function suspend(User $user, User $suspendedBy): User
    {
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        activity()
            ->causedBy($suspendedBy)
            ->performedOn($user)
            ->event('suspended')
            ->log("Suspended {$user->email}");

        return $user;
    }

    public function reactivate(User $user, User $reactivatedBy): User
    {
        $user->forceFill(['status' => UserStatus::Active])->save();

        activity()
            ->causedBy($reactivatedBy)
            ->performedOn($user)
            ->event('reactivated')
            ->log("Reactivated {$user->email}");

        return $user;
    }
}
