<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Resource;
use App\Models\User;
use App\Notifications\BookingDecidedNotification;
use App\Notifications\BookingRequestedNotification;
use App\Services\Google\GoogleBookingSync;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The booking lifecycle: request -> pending -> approved | rejected, and
 * pending/approved -> cancelled. Every transition runs in a transaction
 * that locks the booking row and re-checks its state, so two reviewers (or
 * a reviewer and a requester) acting at once cannot both win. Writes that
 * claim a slot also lock the resource row, which serializes them per room
 * or vehicle. Notifications go out only after the commit.
 */
class BookingService
{
    public function __construct(
        private readonly BookingRuleValidator $rules,
        private readonly ReviewQueue $queue,
    ) {}

    /**
     * Create a booking request: the resource-calendar event and the booking
     * row land in one transaction. A resubmitted form (same person, room and
     * times) gets its first booking back instead of a clash with itself;
     * check `wasRecentlyCreated` to tell the two apart.
     *
     * @param  list<string>  $people  the named attendees or passengers
     */
    public function request(
        User $user,
        Resource $resource,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $purpose,
        array $people,
        string $title,
    ): Booking {
        PeopleList::assertFits($people, $resource);

        $booking = DB::transaction(function () use ($user, $resource, $start, $end, $purpose, $people, $title) {
            $this->lockResource($resource->id);

            $blocking = Booking::query()->blocking($resource->id, $start, $end)->get();

            // Checked before the rules: a resubmitted form already counts
            // toward the per-user cap, and must still get its booking back.
            $resubmitted = $blocking->first(
                fn (Booking $existing) => $existing->requested_by === $user->id
                    && $existing->starts_at->equalTo($start)
                    && $existing->ends_at->equalTo($end),
            );

            if ($resubmitted !== null) {
                return $resubmitted;
            }

            // Inside the transaction so the per-user cap is counted under the same lock as the conflict check.
            $this->rules->validate($user, $resource, $start, $end);

            if ($blocking->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The resource is already booked or requested for that time.',
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
                'timezone' => $user->timezone ?: app(GeneralSettings::class)->timezone,
            ]);

            $booking = Booking::query()->create([
                'resource_id' => $resource->id,
                'event_id' => $event->id,
                'requested_by' => $user->id,
                'status' => $status,
                'starts_at' => $start->avoidMutation()->utc(),
                'ends_at' => $end->avoidMutation()->utc(),
                'purpose' => $purpose,
                'attendee_count' => count($people),
                'people' => $people,
            ]);

            activity()
                ->causedBy($user)
                ->performedOn($booking)
                ->event('requested')
                ->log("Requested {$resource->name}");

            return $booking;
        });

        if ($booking->wasRecentlyCreated && $booking->status === BookingStatus::Pending) {
            Notification::send(
                $this->approversFor($resource),
                new BookingRequestedNotification($booking),
            );
        }

        // Auto-approved types go straight to the requester's Google Calendar.
        if ($booking->wasRecentlyCreated && $booking->status === BookingStatus::Approved) {
            GoogleBookingSync::queue($booking);
        }

        return $booking;
    }

    /**
     * The requester corrects a request still waiting for review: its title,
     * purpose and people. The version guards both ways: a requester editing
     * a stale copy is refused, and a reviewer about to decide on the old
     * copy is refused too, so nobody approves a list they never saw.
     *
     * @param  array{title: string, purpose: string|null, people: list<string>}  $changes
     */
    public function revise(Booking $booking, User $by, array $changes, ?string $version = null): Booking
    {
        // Only the requester: reviewers decide on what was asked, not edit it.
        abort_unless($booking->requested_by === $by->id, 403);

        return DB::transaction(function () use ($booking, $by, $changes, $version) {
            $booking = $this->lockBooking($booking, $version);

            if ($booking->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => $booking->status === BookingStatus::Approved
                        ? 'This booking was approved while you were editing. Request a change to it instead.'
                        : "This booking is already {$booking->status->value}, so it can no longer be edited.",
                ]);
            }

            PeopleList::assertFits($changes['people'], $booking->resource);

            $booking->update([
                'purpose' => $changes['purpose'],
                'attendee_count' => count($changes['people']),
                'people' => $changes['people'],
            ]);

            $booking->event?->update([
                'title' => $changes['title'],
                'description' => $changes['purpose'],
            ]);

            activity()
                ->causedBy($by)
                ->performedOn($booking)
                ->event('revised')
                ->log("Revised booking #{$booking->id} before review");

            return $booking;
        });
    }

    /**
     * @param  string|null  $version  the booking version the reviewer saw; a
     *                                mismatch means they decided on stale data
     */
    public function approve(Booking $booking, User $reviewer, ?string $version = null): Booking
    {
        $booking = DB::transaction(function () use ($booking, $reviewer, $version) {
            $this->lockResource($booking->resource_id);
            $booking = $this->lockBooking($booking, $version);

            $this->assertTransition($booking, BookingStatus::Approved);

            // Approving must not create an overlap that appeared meanwhile.
            $conflicts = Booking::query()
                ->blocking($booking->resource_id, $booking->starts_at, $booking->ends_at)
                ->whereKeyNot($booking->id)
                ->where('status', BookingStatus::Approved->value)
                ->exists();

            if ($conflicts) {
                throw ValidationException::withMessages([
                    'status' => 'An approved booking already occupies that slot. Reject this request, or cancel the approved booking first.',
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

            return $booking;
        });

        $this->queue->settle($booking);
        $booking->requester?->notify(new BookingDecidedNotification($booking));
        GoogleBookingSync::queue($booking);

        return $booking;
    }

    public function reject(Booking $booking, User $reviewer, string $reason, ?string $version = null): Booking
    {
        $booking = DB::transaction(function () use ($booking, $reviewer, $reason, $version) {
            $booking = $this->lockBooking($booking, $version);

            $this->assertTransition($booking, BookingStatus::Rejected);

            $booking->update([
                'status' => BookingStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $booking->event?->update(['status' => EventStatus::Cancelled]);

            activity()
                ->causedBy($reviewer)
                ->performedOn($booking)
                ->event('rejected')
                ->log("Rejected booking #{$booking->id}");

            return $booking;
        });

        $this->queue->settle($booking);
        $booking->requester?->notify(new BookingDecidedNotification($booking));
        GoogleBookingSync::queue($booking);

        return $booking;
    }

    /**
     * Cancelling frees the slot; the row, its reasons and the audit entry
     * stay as history.
     */
    public function cancel(Booking $booking, User $by, ?string $reason = null, ?string $version = null): Booking
    {
        $booking = DB::transaction(function () use ($booking, $by, $reason, $version) {
            $booking = $this->lockBooking($booking, $version);

            $this->assertTransition($booking, BookingStatus::Cancelled);

            $wasApproved = $booking->status === BookingStatus::Approved;

            $booking->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $booking->event?->update(['status' => EventStatus::Cancelled]);

            activity()
                ->causedBy($by)
                ->performedOn($booking)
                ->event('cancelled')
                ->withProperties(['was' => $wasApproved ? 'approved' : 'pending', 'reason' => $reason])
                ->log("Cancelled booking #{$booking->id}");

            return $booking;
        });

        // A withdrawn request no longer needs anyone's decision.
        $this->queue->settle($booking);

        if ($by->isNot($booking->requester)) {
            $booking->requester?->notify(new BookingDecidedNotification($booking));
        }

        GoogleBookingSync::queue($booking);

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

    /**
     * What a client must echo back to act on the booking it is showing. It
     * changes with every write to the row.
     */
    public static function versionOf(Booking $booking): ?string
    {
        // ponytail: second precision; two writes inside one second share a version.
        return $booking->updated_at?->toIso8601String();
    }

    /**
     * One writer per room or vehicle at a time. The overlap check is a range
     * query, and a range lock alone does not stop a concurrent insert into an
     * empty gap; locking the resource row does.
     */
    private function lockResource(int $resourceId): void
    {
        Resource::withTrashed()->whereKey($resourceId)->lockForUpdate()->first();
    }

    private function lockBooking(Booking $booking, ?string $version): Booking
    {
        $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);

        if ($version !== null && $version !== self::versionOf($locked)) {
            abort(409, 'This booking changed since you opened it. The latest version is now showing; check it and try again.');
        }

        return $locked;
    }

    private function assertTransition(Booking $booking, BookingStatus $next): void
    {
        if (! $booking->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "This booking is already {$booking->status->value}, so it cannot be {$this->verb($next)}.",
            ]);
        }
    }

    private function verb(BookingStatus $next): string
    {
        return match ($next) {
            BookingStatus::Approved => 'approved',
            BookingStatus::Rejected => 'rejected',
            BookingStatus::Cancelled => 'cancelled',
            BookingStatus::Pending => 'reopened',
        };
    }
}
