<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventOccurrenceResource;
use App\Models\CalendarUserPreference;
use App\Models\Event;
use App\Services\Calendar\CalendarAccessService;
use App\Services\Event\OccurrenceExpander;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventWindowController extends Controller
{
    /**
     * Occurrences for a date window across the user's selected calendars.
     * Requested calendar ids are intersected with the accessible set, so an
     * inaccessible id silently yields nothing rather than leaking existence.
     */
    public function __invoke(
        Request $request,
        CalendarAccessService $access,
        OccurrenceExpander $expander,
    ): JsonResponse {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'calendars' => ['sometimes', 'array'],
            'calendars.*' => ['integer'],
        ]);

        $start = Carbon::parse($validated['start']);
        $end = Carbon::parse($validated['end']);

        abort_if($start->diffInDays($end) > 190, 422, 'Window too large: request at most ~6 months.');

        $user = $request->user();

        $accessible = $access->accessibleCalendars($user)->keyBy('id');

        $calendarIds = isset($validated['calendars'])
            ? array_values(array_intersect(array_map('intval', $validated['calendars']), $accessible->keys()->all()))
            : $accessible->keys()->all();

        if ($calendarIds === []) {
            return response()->json(['data' => []]);
        }

        $roots = Event::query()
            ->whereIn('calendar_id', $calendarIds)
            ->windowRoots($start, $end)
            ->with(['eventType', 'calendar', 'overrides'])
            ->get();

        $preferences = CalendarUserPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('calendar_id', $calendarIds)
            ->whereNotNull('color_override')
            ->pluck('color_override', 'calendar_id');

        $writable = $accessible
            ->map(fn ($calendar) => $access->canWriteEvents($user, $calendar));

        $occurrences = $expander->expand($roots, $start, $end);

        return response()->json([
            'data' => array_map(
                fn ($occurrence) => (new EventOccurrenceResource(
                    $occurrence,
                    $preferences->get($occurrence->event->calendar_id),
                    (bool) $writable->get($occurrence->event->calendar_id, false),
                ))->toArray($request),
                $occurrences,
            ),
        ]);
    }
}
