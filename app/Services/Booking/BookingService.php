<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Resource;
use App\Models\User;
use App\Notifications\BookingDecidedNotification;
use App\Notifications\BookingRequestedNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly BookingRuleValidator $rules,
    ) {}

    /**
     * Create a booking request: the resource-calendar event and the booking
     * row land in one transaction. Conflicting rows are locked and re-checked
     * before commit, so a double-booking cannot slip through concurrently.
     */
    public function request(
        User $user,
        Resource $resource,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $purpose,
        ?int $attendeeCount,
        string $title,
    ): Booking {
        $this->rules->validate($user, $resource, $start, $end);

        if ($attendeeCount !== null && $resource->capacity !== null && $attendeeCount > $resource->capacity) {
            throw ValidationException::withMessages([
                'attendee_count' => "The resource holds at most {$resource->capacity} people.",
            ]);
        }

        $booking = DB::transaction(function () use ($user, $resource, $start, $end, $purpose, $attendeeCount, $title) {
            $conflicts = Booking::query()
                ->blocking($resource->id, $start, $end)
                ->lockForUpdate()
                ->exists();

            if ($conflicts) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The resource is already booked for that time.',
                ]);
            }

            $status = $resource->type->requires_approval
                ? BookingStatus::Pending
                : BookingStatus::Approved;

            $event = Event::query()->create([
                'calendar_id' => $resource->calendar_id,
                'created_by' => $user->id,
                'title' => $title,
                'description' => $purpose,
                'starts_at' => $start->avoidMutation()->utc(),
                'ends_at' => $end->avoidMutation()->utc(),
                'timezone' => $user->timezone ?: 'Asia/Manila',
            ]);

            return Booking::query()->create([
                'resource_id' => $resource->id,
                'event_id' => $event->id,
                'requested_by' => $user->id,
                'status' => $status,
                'starts_at' => $start->avoidMutation()->utc(),
                'ends_at' => $end->avoidMutation()->utc(),
                'purpose' => $purpose,
                'attendee_count' => $attendeeCount,
            ]);
        });

        if ($booking->status === BookingStatus::Pending) {
            Notification::send(
                $this->approversFor($resource),
                new BookingRequestedNotification($booking),
            );
        }

        activity()
            ->causedBy($user)
            ->performedOn($booking)
            ->event('requested')
            ->log("Requested {$resource->name}");

        return $booking;
    }

    public function approve(Booking $booking, User $reviewer): Booking
    {
        return DB::transaction(function () use ($booking, $reviewer) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            $this->assertTransition($booking, BookingStatus::Approved);

            // Approving must not create an overlap that appeared meanwhile.
            $conflicts = Booking::query()
                ->blocking($booking->resource_id, $booking->starts_at, $booking->ends_at)
                ->whereKeyNot($booking->id)
                ->where('status', BookingStatus::Approved->value)
                ->lockForUpdate()
                ->exists();

            if ($conflicts) {
                throw ValidationException::withMessages([
                    'status' => 'An approved booking already occupies that slot.',
                ]);
            }

            $booking->update([
                'status' => BookingStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            activity()
                ->causedBy($reviewer)
                ->performedOn($booking)
                ->event('approved')
                ->log("Approved booking #{$booking->id}");

            $booking->requester?->notify(new BookingDecidedNotification($booking));

            return $booking;
        });
    }

    public function reject(Booking $booking, User $reviewer, string $reason): Booking
    {
        $this->assertTransition($booking, BookingStatus::Rejected);

        $booking->update([
            'status' => BookingStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        activity()
            ->causedBy($reviewer)
            ->performedOn($booking)
            ->event('rejected')
            ->log("Rejected booking #{$booking->id}");

        $booking->requester?->notify(new BookingDecidedNotification($booking));

        return $booking;
    }

    public function cancel(Booking $booking, User $by, ?string $reason = null): Booking
    {
        $this->assertTransition($booking, BookingStatus::Cancelled);

        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_by' => $by->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        activity()
            ->causedBy($by)
            ->performedOn($booking)
            ->event('cancelled')
            ->log("Cancelled booking #{$booking->id}");

        if ($by->isNot($booking->requester)) {
            $booking->requester?->notify(new BookingDecidedNotification($booking));
        }

        return $booking;
    }

    /**
     * Users who approve bookings for this resource's type: holders of the
     * configured approver role plus super admins.
     *
     * @return Collection<int, User>
     */
    public function approversFor(Resource $resource): Collection
    {
        return User::query()
            ->active()
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($q) => $q->whereKey($resource->type->approver_role_id))
                ->orWhereHas('roles', fn ($q) => $q->where('name', 'super_admin')))
            ->get();
    }

    private function assertTransition(Booking $booking, BookingStatus $next): void
    {
        if (! $booking->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$booking->status->value} booking cannot become {$next->value}.",
            ]);
        }
    }
}
