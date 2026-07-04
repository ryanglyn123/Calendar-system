<?php

namespace App\Http\Controllers;

use App\Enums\CalendarType;
use App\Http\Controllers\Concerns\ResolvesInitialWindow;
use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use App\Services\Calendar\CalendarListService;
use App\Services\Event\EventWindowService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The FDCP public calendar: organization-wide calendars only, the events
 * every staff member sees regardless of division.
 */
class PublicCalendarController extends Controller
{
    use ResolvesInitialWindow;

    public function __invoke(
        Request $request,
        GeneralSettings $settings,
        CalendarListService $list,
        EventWindowService $window,
    ): Response {
        $user = $request->user();
        $types = [CalendarType::Organization->value];

        return Inertia::render('PublicCalendar', [
            'eventTypes' => EventTypeResource::collection(
                EventType::query()->active()->orderBy('sort_order')->get(),
            ),
            'weekStartsOn' => $settings->week_starts_on,
            'initialCalendars' => fn () => $list->forUser($request, $user, $types),
            'initialEvents' => fn () => $window->occurrences(
                $request,
                $user,
                ...$this->initialSpan($user->timezone, $settings->week_starts_on),
                types: $types,
            ),
        ]);
    }
}
