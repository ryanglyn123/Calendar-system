<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use App\Services\Calendar\CalendarListService;
use App\Services\Event\EventWindowService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        GeneralSettings $settings,
        CalendarListService $list,
        EventWindowService $window,
    ): Response {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'eventTypes' => EventTypeResource::collection(
                EventType::query()->active()->orderBy('sort_order')->get(),
            ),
            'weekStartsOn' => $settings->week_starts_on,
            // Calendars and the current three-month event window ride along
            // with the page itself, so the grid paints full on first render
            // instead of waiting for follow-up API calls.
            'initialCalendars' => fn () => $list->forUser($request, $user),
            'initialEvents' => fn () => $window->occurrences(
                $request,
                $user,
                ...$this->initialSpan($user->timezone, $settings->week_starts_on),
            ),
        ]);
    }

    /**
     * Previous through next month as full grid weeks, padded a couple of days
     * so client-computed window edges always fall inside the span even when
     * the browser timezone differs from the profile timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function initialSpan(?string $timezone, int $weekStartsOn): array
    {
        $now = Carbon::now($timezone ?? config('app.timezone'));

        $start = $now->copy()->subMonthNoOverflow()->startOfMonth()
            ->startOfWeek($weekStartsOn)->subDays(2);
        $end = $now->copy()->addMonthNoOverflow()->endOfMonth()
            ->endOfWeek($weekStartsOn === Carbon::MONDAY ? Carbon::SUNDAY : Carbon::SATURDAY)
            ->addDays(3);

        return [$start->utc(), $end->utc()];
    }
}
