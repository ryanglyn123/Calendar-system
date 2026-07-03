<?php

namespace App\Services\Identity;

use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\UserInvitedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InvitationService
{
    /**
     * Issue (or re-issue) an invitation. Any previous open invitation for the
     * same email is revoked so only one invitation is ever live per address.
     */
    public function issue(string $email, ?int $divisionId, ?int $roleId, User $invitedBy): Invitation
    {
        return DB::transaction(function () use ($email, $divisionId, $roleId, $invitedBy) {
            Invitation::query()->where('email', $email)->whereNull('accepted_at')->delete();

            $invitation = Invitation::query()->create([
                'email' => $email,
                'token' => Str::random(64),
                'division_id' => $divisionId,
                'role_id' => $roleId,
                'invited_by' => $invitedBy->id,
                'expires_at' => now()->addDays(7),
            ]);

            Notification::route('mail', $email)->notify(new UserInvitedNotification($invitation));

            activity()
                ->causedBy($invitedBy)
                ->performedOn($invitation)
                ->event('invited')
                ->log("Invited {$email}");

            return $invitation;
        });
    }

    public function resend(Invitation $invitation, User $resentBy): Invitation
    {
        return $this->issue($invitation->email, $invitation->division_id, $invitation->role_id, $resentBy);
    }

    /**
     * Accept an invitation: create an active, pre-verified account with the
     * division and role the admin chose when inviting.
     */
    public function accept(Invitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'division_id' => $invitation->division_id,
                'status' => UserStatus::Active,
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
                'approved_at' => now(),
                'approved_by' => $invitation->invited_by,
            ])->save();

            $user->assignRole($invitation->role_id !== null ? $invitation->role->name : 'staff');

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });
    }
}
