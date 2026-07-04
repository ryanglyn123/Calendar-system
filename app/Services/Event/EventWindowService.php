<?php

namespace App\Services\Event;

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
    public function __construct(
        private readonly CalendarAccessService $access,
        private readonly OccurrenceExpander $expander,
    ) {}

    /**
     * Occurrences in [start, end) across the user's calendars. Requested
     * calendar ids are intersected with the accessible set, so an
     * inaccessible id silently yields nothing rather than leaking existence.
     * Types and resource type narrow the set the same way for scoped pages.
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

        if ($calendarIds === []) {
            return [];
        }

        $roots = Event::query()
            ->whereIn('calendar_id', $calendarIds)
            ->windowRoots($start, $end)
            ->with([
                'eventType', 'calendar', 'overrides', 'attendees.user', 'reminders',
                'creator', 'booking.resource.type', 'booking.requester', 'booking.reviewer',
            ])
            ->get();

        $preferences = CalendarUserPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('calendar_id', $calendarIds)
            ->whereNotNull('color_override')
            ->pluck('color_override', 'calendar_id');

        $writable = $accessible
            ->map(fn (Calendar $calendar) => $this->access->canWriteEvents($user, $calendar));

        return array_map(
            fn (EventOccurrence $occurrence) => (new EventOccurrenceResource(
                $occurrence,
                $preferences->get($occurrence->event->calendar_id),
                (bool) $writable->get($occurrence->event->calendar_id, false),
            ))->toArray($request),
            $this->expander->expand($roots, $start, $end),
        );
    }
}
