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

class DashboardController extends Controller
{
    use ResolvesInitialWindow;

    public function __invoke(
        Request $request,
        GeneralSettings $settings,
        CalendarListService $list,
        EventWindowService $window,
    ): Response {
        $user = $request->user();

        // Resource calendars live on their own section pages (Boardroom,
        // Carpool, ...), so the merged calendar only carries the rest.
        $types = [
            CalendarType::Organization->value,
            CalendarType::Division->value,
            CalendarType::Personal->value,
            // The user's own Google calendars, mirrored read-only.
            CalendarType::Google->value,
        ];

        return Inertia::render('Dashboard', [
            'eventTypes' => EventTypeResource::collection(
                EventType::query()->active()->visibleTo($request->user())->orderBy('sort_order')->get(),
            ),
            'weekStartsOn' => $settings->week_starts_on,
            // Calendars and the current three-month event window ride along
            // with the page itself, so the grid paints full on first render
            // instead of waiting for follow-up API calls.
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
