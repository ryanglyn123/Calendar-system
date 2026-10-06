<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\StorePersonalCalendarRequest;
use App\Http\Requests\Calendar\UpdateCalendarRequest;
use App\Models\Calendar;
use App\Services\Calendar\CalendarService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;

class PersonalCalendarController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendars,
    ) {}

    public function store(StorePersonalCalendarRequest $request): RedirectResponse
    {
        $this->calendars->createPersonalCalendar($request->user(), $request->validated());

        Toast::success('Calendar created.');

        return back();
    }

    public function update(UpdateCalendarRequest $request, Calendar $calendar): RedirectResponse
    {
        $this->calendars->update($calendar, $request->validated());

        Toast::success('Calendar updated.');

        return back();
    }

    public function destroy(Calendar $calendar): RedirectResponse
    {
        $this->authorize('delete', $calendar);

        // Managed calendars are archived/deleted from the admin panel, not here.
        abort_unless($calendar->isPersonal(), 403, 'Only personal calendars can be deleted here.');
        abort_if($calendar->is_default, 403, 'Your own calendar cannot be deleted.');

        $this->calendars->delete($calendar);

        Toast::success('Calendar deleted.');

        return back();
    }
}
