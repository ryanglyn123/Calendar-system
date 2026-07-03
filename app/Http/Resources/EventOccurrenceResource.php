<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Event\EventOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes one occurrence for the calendar grid. Private events are
 * masked to "Busy" for everyone but their creator.
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

        return [
            'occurrence_id' => $this->occurrence->id(),
            'event_id' => $event->id,
            'calendar_id' => $event->calendar_id,
            'title' => $masked ? 'Busy' : $event->title,
            'description' => $masked ? null : $event->description,
            'location' => $masked ? null : $event->location,
            'meeting_url' => $masked ? null : $event->meeting_url,
            'starts_at' => $this->occurrence->startsAt->toIso8601String(),
            'ends_at' => $this->occurrence->endsAt->toIso8601String(),
            'all_day' => $event->all_day,
            'status' => $event->status->value,
            // Booking status projects onto color at render time, never stored.
            'color' => ($event->relationLoaded('booking') ? $event->booking?->status->color() : null)
                ?? $event->color
                ?? $event->eventType->color
                ?? $this->preferenceColor
                ?? $event->calendar->color,
            'booking' => ! $event->relationLoaded('booking') || $event->booking === null
                ? null
                : [
                    'id' => $event->booking->id,
                    'status' => $event->booking->status->value,
                    'resource' => $event->booking->resource?->name,
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
                    'rsvp_status' => $attendee->rsvp_status->value,
                    'is_organizer' => $attendee->is_organizer,
                ])->values()->all(),
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
        ];
    }
}
