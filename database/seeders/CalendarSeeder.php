<?php

namespace Database\Seeders;

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
use App\Services\Calendar\CalendarService;
use Illuminate\Database\Seeder;

class CalendarSeeder extends Seeder
{
    public function run(CalendarService $calendars): void
    {
        $organizationCalendars = [
            [
                'name' => 'FDCP Events',
                'description' => 'Organization-wide events, festivals and activities.',
                'color' => '#F6BF26',
                'icon' => 'clapperboard',
                'is_default' => true,
                'sort_order' => 0,
            ],
            [
                'name' => 'Trainings & Workshops',
                'description' => 'Capacity building, film education and workshops.',
                'color' => '#039BE5',
                'icon' => 'graduation-cap',
                'is_default' => true,
                'sort_order' => 1,
            ],
        ];

        foreach ($organizationCalendars as $attributes) {
            Calendar::query()->firstOrCreate(
                [
                    'type' => CalendarType::Organization->value,
                    'name' => $attributes['name'],
                ],
                [...$attributes, 'is_active' => true],
            );
        }

        // Every division owns a shared calendar; the observer covers new
        // divisions, this covers ones seeded before it existed.
        Division::query()->each(fn (Division $division) => $calendars->createDivisionCalendar($division));
    }
}
