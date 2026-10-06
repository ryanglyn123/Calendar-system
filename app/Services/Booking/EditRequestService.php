<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\EditRequestStatus;
use App\Models\Booking;
use App\Models\BookingEditRequest;
use App\Models\User;
use App\Notifications\EditRequestDecidedNotification;
use App\Notifications\EditRequestSubmittedNotification;
use App\Services\Google\GoogleBookingSync;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class EditRequestService
{
    public function __construct(
        private readonly BookingRuleValidator $rules,
    ) {}

    /**
     * Approved bookings cannot be edited in place; the owner proposes a
     * change instead. One pending proposal per booking at a time.
     *
     * @param  array{starts_at?: CarbonInterface|null, ends_at?: CarbonInterface|null, purpose?: string|null, people?: list<string>|null, note?: string|null}  $proposal
     */
    public function submit(Booking $booking, User $user, array $proposal): BookingEditRequest
    {
        return DB::transaction(function () use ($booking, $user, $proposal) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Approved) {
                throw ValidationException::withMessages([
                    'booking' => 'Only approved bookings take edit requests; pending ones can be cancelled and re-made.',
                ]);
            }

            $people = $proposal['people'] ?? null;

            if ($people !== null) {
                PeopleList::assertFits($people, $booking->resource);
            }

            $changes = array_filter([
                $proposal['starts_at'] ?? null,
                $proposal['ends_at'] ?? null,
                $proposal['purpose'] ?? null,
                $people,
            ], fn ($value) => $value !== null);

            if ($changes === []) {
                throw ValidationException::withMessages([
                    'booking' => 'Change at least one thing: the time, the purpose or the people.',
                ]);
            }

            if ($booking->hasPendingEditRequest()) {
                throw ValidationException::withMessages([
                    'booking' => 'This booking already has a pending change request.',
                ]);
            }

            $editRequest = $booking->editRequests()->create([
                'requested_by' => $user->id,
                'proposed_starts_at' => $proposal['starts_at'] ?? null,
                'proposed_ends_at' => $proposal['ends_at'] ?? null,
                'proposed_purpose' => $proposal['purpose'] ?? null,
                'proposed_attendee_count' => $people === null ? null : count($people),
                'proposed_people' => $people,
                'note' => $proposal['note'] ?? null,
            ]);

            Notification::send(
                app(BookingService::class)->approversFor($booking->resource),
                new EditRequestSubmittedNotification($editRequest),
            );

            return $editRequest;
        });
    }

    /**
     * Apply a proposal: booking and its event move together, and the new
     * slot is validated and conflict-checked like a fresh booking.
     */
    public function apply(BookingEditRequest $editRequest, User $reviewer): BookingEditRequest
    {
        return DB::transaction(function () use ($editRequest, $reviewer) {
            $editRequest = BookingEditRequest::query()->lockForUpdate()->findOrFail($editRequest->id);

            $this->assertPending($editRequest);

            $booking = $editRequest->booking;
            $newStart = $editRequest->proposed_starts_at ?? $booking->starts_at;
            $newEnd = $editRequest->proposed_ends_at ?? $booking->ends_at;

            $movesTime = $editRequest->proposed_starts_at !== null || $editRequest->proposed_ends_at !== null;

            if ($movesTime && $newStart->isPast()) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The proposed time has already passed. Reject this request and ask for a new one.',
                ]);
            }

            if ($movesTime) {
                $this->rules->validate($booking->requester, $booking->resource, $newStart, $newEnd, $booking);

                $conflicts = Booking::query()
                    ->blocking($booking->resource_id, $newStart, $newEnd)
                    ->whereKeyNot($booking->id)
                    ->lockForUpdate()
                    ->exists();

                if ($conflicts) {
                    throw ValidationException::withMessages([
                        'starts_at' => 'The proposed slot is no longer free.',
                    ]);
                }
            }

            $booking->update([
                'starts_at' => $newStart,
                'ends_at' => $newEnd,
                'purpose' => $editRequest->proposed_purpose ?? $booking->purpose,
                'attendee_count' => $editRequest->proposed_attendee_count ?? $booking->attendee_count,
                'people' => $editRequest->proposed_people ?? $booking->people,
            ]);

            $booking->event?->update([
                'starts_at' => $newStart,
                'ends_at' => $newEnd,
                'description' => $booking->purpose,
            ]);

            $editRequest->update([
                'status' => EditRequestStatus::Applied,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            activity()
                ->causedBy($reviewer)
                ->performedOn($booking)
                ->event('edit-applied')
                ->log("Applied change request #{$editRequest->id}");

            $editRequest->requester?->notify(new EditRequestDecidedNotification($editRequest));

            // The approved change moves the Google copy too (after commit).
            GoogleBookingSync::queue($booking);

            return $editRequest;
        });
    }

    public function reject(BookingEditRequest $editRequest, User $reviewer, string $reason): BookingEditRequest
    {
        $this->assertPending($editRequest);

        $editRequest->update([
            'status' => EditRequestStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $editRequest->requester?->notify(new EditRequestDecidedNotification($editRequest));

        return $editRequest;
    }

    private function assertPending(BookingEditRequest $editRequest): void
    {
        if ($editRequest->status !== EditRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'This change request was already resolved.',
            ]);
        }
    }
}
