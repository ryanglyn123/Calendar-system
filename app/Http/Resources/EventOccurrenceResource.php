<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Event\EventOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes one occurrence for the calendar grid. Private events are
 * masked to "Busy" for everyone but their creator. A booking shows its full
 * record (purpose, headcount, reasons, reviewer) only to the requester and
 * to people who review that resource type; everyone else sees who holds
 * the slot and its state.
 */
class EventOccurrenceResource extends JsonResource
{
    public function __construct(
        private readonly EventOccurrence $occurrence,
        private readonly ?string $preferenceColor,
        private readonly bool $canEdit,
    ) {
        parent::__construct($occurrence);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $event = $this->occurrence->event;

        $isCreator = $event->created_by === $user->id;
        $masked = $event->visibility === 'private' && ! $isCreator;

        $booking = $event->relationLoaded('booking') ? $event->booking : null;
        $isMine = $booking !== null && $booking->requested_by === $user->id;
        $canReview = $booking !== null && $user->can('approve', $booking);
        $fullBooking = $isMine || $canReview;

        return [
            'occurrence_id' => $this->occurrence->id(),
            'event_id' => $event->id,
            'calendar_id' => $event->calendar_id,
            'title' => $masked ? 'Busy' : $event->title,
            // A booking event's description is its purpose.
            'description' => $masked || ($booking !== null && ! $fullBooking) ? null : $event->description,
            'location' => $masked ? null : $event->location,
            'meeting_url' => $masked ? null : $event->meeting_url,
            'starts_at' => $this->occurrence->startsAt->toIso8601String(),
            'ends_at' => $this->occurrence->endsAt->toIso8601String(),
            'all_day' => $event->all_day,
            'status' => $event->status->value,
            // Bookings keep their room's or vehicle's color; their state is
            // drawn from booking.status (fill pattern and icon), not from hue.
            'color' => $event->color
                ?? $event->eventType->color
                ?? $this->preferenceColor
                ?? $event->calendar->color,
            'booking' => $masked || $booking === null
                ? null
                : [
                    'id' => $booking->id,
                    'reference' => $booking->reference(),
                    'status' => $booking->status->value,
                    'status_label' => $booking->status->label(),
                    'status_color' => $booking->status->color(),
                    'resource' => $booking->resource?->name,
                    'resource_type' => $booking->resource?->relationLoaded('type')
                        ? $booking->resource->type?->name
                        : null,
                    'resource_type_slug' => $booking->resource?->relationLoaded('type')
                        ? $booking->resource->type?->slug
                        : null,
                    'capacity' => $booking->resource?->capacity,
                    'requested_by' => $booking->relationLoaded('requester')
                        ? $booking->requester?->name
                        : null,
                    'is_mine' => $isMine,
                    'can_review' => $canReview,
                    'can_cancel' => $fullBooking && $user->can('cancel', $booking),
                    'purpose' => $fullBooking ? $booking->purpose : null,
                    'attendee_count' => $fullBooking ? $booking->attendee_count : null,
                    'people' => $fullBooking ? ($booking->people ?? []) : null,
                    'requested_at' => $fullBooking ? $booking->created_at?->toIso8601String() : null,
                    'reviewed_by' => $fullBooking && $booking->relationLoaded('reviewer')
                        ? $booking->reviewer?->name
                        : null,
                    'reviewed_at' => $fullBooking ? $booking->reviewed_at?->toIso8601String() : null,
                    'rejection_reason' => $fullBooking ? $booking->rejection_reason : null,
                    'cancellation_reason' => $fullBooking ? $booking->cancellation_reason : null,
                    'cancelled_by_reviewer' => $fullBooking
                        && $booking->cancelled_by !== null
                        && $booking->cancelled_by !== $booking->requested_by,
                    'version' => $fullBooking ? BookingService::versionOf($booking) : null,
                ],
            'event_type' => $masked || ! $event->relationLoaded('eventType') || $event->eventType === null
                ? null
                : EventTypeResource::make($event->eventType)->toArray($request),
            'is_recurring' => $event->isRecurring() || $event->isOverride(),
            'rrule' => $masked ? null : $event->rrule,
            'attendees' => $masked || ! $event->relationLoaded('attendees')
                ? []
                : $event->attendees->map(fn ($attendee) => [
                    'user_id' => $attendee->user_id,
                    'name' => $attendee->user->name ?? $attendee->name,
                    'avatar_path' => $attendee->user?->avatar_path,
                    'rsvp_status' => $attendee->rsvp_status->value,
                    'is_organizer' => $attendee->is_organizer,
                ])->values()->all(),
            'organizer' => $masked || ! $event->relationLoaded('creator') || $event->creator === null
                ? null
                : [
                    'user_id' => $event->creator->id,
                    'name' => $event->creator->name,
                    'avatar_path' => $event->creator->avatar_path,
                ],
            'my_rsvp' => $event->relationLoaded('attendees')
                ? $event->attendees->firstWhere('user_id', $user->id)?->rsvp_status->value
                : null,
            'my_reminder_minutes' => $event->relationLoaded('reminders')
                ? $event->reminders
                    ->where('user_id', $user->id)
                    ->where('channel', 'database')
                    ->pluck('minutes_before')
                    ->unique()
                    ->values()
                    ->all()
                : [],
            'is_private' => $event->visibility === 'private',
            'is_masked' => $masked,
            'can_edit' => $this->canEdit && ! $masked,
            'created_by' => $event->created_by,
            'timezone' => $event->timezone,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }
}
