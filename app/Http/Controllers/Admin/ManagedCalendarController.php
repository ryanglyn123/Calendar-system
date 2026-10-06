<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalendarType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreManagedCalendarRequest;
use App\Http\Requests\Admin\UpdateManagedCalendarRequest;
use App\Http\Resources\CalendarResource;
use App\Http\Resources\DivisionResource;
use App\Models\Calendar;
use App\Models\Division;
use App\Services\Calendar\CalendarService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ManagedCalendarController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendars,
    ) {}

    public function index(): Response
    {
        abort_unless(request()->user()->can('calendars.manage'), 403);

        $calendars = Calendar::query()
            ->whereIn('type', [CalendarType::Organization->value, CalendarType::Division->value])
            ->with('division')
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/calendars/Index', [
            'calendars' => CalendarResource::collection($calendars),
            'divisions' => DivisionResource::collection(Division::query()->active()->orderBy('sort_order')->get()),
        ]);
    }

    public function store(StoreManagedCalendarRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->calendars->createManagedCalendar(
            CalendarType::from($validated['type']),
            collect($validated)->except('type')->all(),
            $request->user(),
        );

        Toast::success('Calendar created.');

        return back();
    }

    public function update(UpdateManagedCalendarRequest $request, Calendar $calendar): RedirectResponse
    {
        abort_if($calendar->isPersonal() || $calendar->type === CalendarType::Google, 404);

        $this->calendars->update($calendar, $request->validated());

        Toast::success('Calendar updated.');

        return back();
    }

    public function destroy(Calendar $calendar): RedirectResponse
    {
        abort_unless(request()->user()->can('calendars.manage'), 403);
        abort_if($calendar->isPersonal() || $calendar->type === CalendarType::Google, 404);
        abort_if($calendar->type === CalendarType::Resource, 403, 'Resource calendars are managed through their resource.');

        $this->calendars->delete($calendar);

        Toast::success('Calendar deleted.');

        return back();
    }
}
