<?php

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $booking->requested_by === $user->id
            || $this->approve($user, $booking);
    }

    /**
     * Approving needs the permission AND the resource type's approver role;
     * super admins pass through Gate::before.
     */
    public function approve(User $user, Booking $booking): bool
    {
        return $user->can('bookings.approve')
            && $user->roles->contains('id', $booking->resource?->type?->approver_role_id);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        $actorAllowed = $booking->requested_by === $user->id
            || $this->approve($user, $booking);

        return $actorAllowed
            && in_array($booking->status, [BookingStatus::Pending, BookingStatus::Approved], true);
    }

    public function requestEdit(User $user, Booking $booking): bool
    {
        return $booking->requested_by === $user->id
            && $booking->status === BookingStatus::Approved;
    }
}
