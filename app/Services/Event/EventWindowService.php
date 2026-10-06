<?php

namespace App\Services\Event;

use App\Enums\BookingStatus;
use App\Http\Resources\EventOccurrenceResource;
use App\Models\Calendar;
use App\Models\CalendarUserPreference;
use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\CalendarAccessService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * Builds the serialized occurrence payload for a date window. Shared by the
 * window endpoint and the dashboard's inlined first paint so both produce
 * byte-identical occurrence shapes.
 */
class EventWindowService
{
    private const EAGER = [
        'eventType', 'calendar', 'overrides', 'attendees.user', 'reminders',
        'creator', 'booking.resource.type', 'booking.requester', 'booking.reviewer',
        'googleEvent',
    ];

    public function __construct(
        private readonly CalendarAccessService $access,
        private readonly OccurrenceExpander $expander,
        private readonly HolidayOccurrences $holidays,
    ) {}

    /**
     * Occurrences in [start, end) across the user's calendars. Requested
     * calendar ids are intersected with the accessible set, so an
     * inaccessible id silently yields nothing rather than leaking existence.
     * Types and resource type narrow the set the same way for scoped pages.
     *
     * My Calendar (a scope with personal calendars and no resource ones)
     * also carries the user's own room and vehicle bookings, in every state.
     *
     * Every window also carries the organization's holidays, so they show
     * on every calendar (and on the booking pages, where they matter most).
     *
     * @param  list<int>|null  $requestedCalendarIds
     * @param  list<string>|null  $types
     * @return list<array<string, mixed>>
     */
    public function occurrences(
        Request $request,
        User $user,
        CarbonInterface $start,
        CarbonInterface $end,
        ?array $requestedCalendarIds = null,
        ?array $types = null,
        ?int $resourceTypeId = null,
    ): array {
        $accessible = $this->access
            ->scoped($this->access->accessibleCalendars($user), $types, $resourceTypeId)
            ->keyBy('id');

        $calendarIds = $requestedCalendarIds === null
            ? $accessible->keys()->all()
            : array_values(array_intersect(
                array_map('intval', $requestedCalendarIds),
                $accessible->keys()->all(),
            ));

        $withOwnBookings = $types !== null && in_array('personal', $types, true) && ! in_array('resource', $types, true);

        if ($calendarIds === [] && ! $withOwnBookings) {
            return $this->holidays->between($start, $end);
        }

        $roots = Event::query()
            ->whereIn('calendar_id', $calendarIds)
            ->windowRoots($start, $end)
            ->with(self::EAGER)
            ->get()
            ->reject(fn (Event $event) => $this->hidesClosedBooking($user, $event));

        if ($withOwnBookings) {
            $roots = $roots->concat(
                Event::query()
                    ->whereNotIn('calendar_id', $calendarIds)
                    ->whereHas('booking', fn ($query) => $query->where('requested_by', $user->id))
                    ->windowRoots($start, $end)
                    ->with(self::EAGER)
                    ->get(),
            );
        }

        $preferences = CalendarUserPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('calendar_id', $calendarIds)
            ->whereNotNull('color_override')
            ->pluck('color_override', 'calendar_id');

        $writable = $accessible
            ->map(fn (Calendar $calendar) => $this->access->canWriteEvents($user, $calendar));

        return [
            ...array_map(
                fn (EventOccurrence $occurrence) => (new EventOccurrenceResource(
                    $occurrence,
                    $preferences->get($occurrence->event->calendar_id),
                    (bool) $writable->get($occurrence->event->calendar_id, false),
                ))->toArray($request),
                $this->expander->expand($roots, $start, $end),
            ),
            ...$this->holidays->between($start, $end),
        ];
    }

    /**
     * Someone else's rejected or cancelled booking is their business: it no
     * longer holds the slot, so only the requester and the type's reviewers
     * see it.
     */
    private function hidesClosedBooking(User $user, Event $event): bool
    {
        $booking = $event->booking;

        return $booking !== null
            && in_array($booking->status, [BookingStatus::Rejected, BookingStatus::Cancelled], true)
            && $booking->requested_by !== $user->id
            && ! $user->can('approve', $booking);
    }
}
