<?php

namespace App\Observers;

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
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
        if (! $division->wasChanged('is_active')) {
            return;
        }

        Calendar::query()
            ->where('type', CalendarType::Division->value)
            ->where('division_id', $division->id)
            ->update(['is_active' => $division->is_active]);
    }
}
