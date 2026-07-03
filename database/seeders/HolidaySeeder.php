<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\WorkingHour;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $holidays = [
            ['New Year\'s Day', '2026-01-01', true, 'regular'],
            ['Araw ng Kagitingan', '2026-04-09', true, 'regular'],
            ['Maundy Thursday', '2026-04-02', false, 'regular'],
            ['Good Friday', '2026-04-03', false, 'regular'],
            ['Labor Day', '2026-05-01', true, 'regular'],
            ['Independence Day', '2026-06-12', true, 'regular'],
            ['Ninoy Aquino Day', '2026-08-21', true, 'special_non_working'],
            ['National Heroes Day', '2026-08-31', false, 'regular'],
            ['Bonifacio Day', '2026-11-30', true, 'regular'],
            ['Christmas Day', '2026-12-25', true, 'regular'],
            ['Rizal Day', '2026-12-30', true, 'regular'],
        ];

        foreach ($holidays as [$name, $date, $recurs, $type]) {
            Holiday::query()->firstOrCreate(
                ['name' => $name, 'date' => $date],
                ['recurs_annually' => $recurs, 'type' => $type, 'blocks_bookings' => true],
            );
        }

        // Organization default: Monday to Friday, 08:00 to 18:00.
        foreach (range(0, 6) as $day) {
            WorkingHour::query()->firstOrCreate(
                ['resource_id' => null, 'day_of_week' => $day],
                in_array($day, [0, 6], true)
                    ? ['opens_at' => null, 'closes_at' => null]
                    : ['opens_at' => '08:00', 'closes_at' => '18:00'],
            );
        }
    }
}
