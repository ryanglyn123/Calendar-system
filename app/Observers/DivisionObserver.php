<?php

namespace App\Observers;

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
use App\Services\Calendar\CalendarAccessService;
use App\Services\Calendar\CalendarService;

class DivisionObserver
{
    public function __construct(
        private readonly CalendarService $calendars,
    ) {}

    public function created(Division $division): void
    {
        $this->calendars->createDivisionCalendar($division);
    }

    public function updated(Division $division): void
    {
        if ($division->wasChanged('is_active')) {
            Calendar::query()
                ->where('type', CalendarType::Division->value)
                ->where('division_id', $division->id)
                ->update(['is_active' => $division->is_active]);
        }

        // Activation and re-parenting both change who may see which
        // division calendars; the accessible set is cached per user.
        if ($division->wasChanged(['is_active', 'parent_id'])) {
            app(CalendarAccessService::class)->forgetAll();
        }
    }

    /**
     * A deleted division must not leave a live calendar behind.
     */
    public function deleted(Division $division): void
    {
        Calendar::query()
            ->where('division_id', $division->id)
            ->get()
            ->each(fn (Calendar $calendar) => $this->calendars->delete($calendar));
    }
}
