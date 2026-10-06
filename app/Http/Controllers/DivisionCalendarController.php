<?php

namespace App\Http\Controllers;

use App\Enums\CalendarType;
use App\Http\Controllers\Concerns\ResolvesInitialWindow;
use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use App\Services\Calendar\CalendarListService;
use App\Services\Event\EventWindowService;
use App\Settings\GeneralSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's division calendar: visible to that division only, and every
 * member of the division can create and edit its events.
 */
class DivisionCalendarController extends Controller
{
    use ResolvesInitialWindow;

    public function __invoke(
        Request $request,
        GeneralSettings $settings,
        CalendarListService $list,
        EventWindowService $window,
    ): Response|RedirectResponse {
        $user = $request->user();

        // Google signups and legacy accounts can be active without a
        // division; there is nothing to show them here.
        if ($user->division_id === null) {
            return redirect()->route('dashboard');
        }

        $types = [CalendarType::Division->value];

        return Inertia::render('DivisionCalendar', [
            'divisionName' => $user->division->name,
            'eventTypes' => EventTypeResource::collection(
                EventType::query()->active()->visibleTo($request->user())->orderBy('sort_order')->get(),
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
