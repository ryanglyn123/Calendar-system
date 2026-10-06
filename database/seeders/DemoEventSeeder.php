<?php

namespace Database\Seeders;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Sample events for local demos — safe to re-run, never for production.
 */
class DemoEventSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@fdcp.gov.ph')->firstOrFail();

        $personal = Calendar::query()->firstOrCreate(
            ['type' => 'personal', 'owner_id' => $admin->id, 'name' => 'My Projects'],
            ['color' => '#4285F4', 'is_default' => false, 'is_active' => true],
        );

        $fdcp = Calendar::query()->where('name', 'FDCP Events')->firstOrFail();
        $training = Calendar::query()->where('name', 'Trainings & Workshops')->firstOrFail();
        $mis = Calendar::query()
            ->where('type', 'division')
            ->whereHas('division', fn ($q) => $q->where('acronym', 'ITU'))
            ->firstOrFail();

        $monday = Carbon::now('Asia/Manila')->startOfWeek();

        $events = [
            [$fdcp, 'Pista ng Pelikulang Pilipino', $monday->copy()->addDays(3)->setTime(9, 0), $monday->copy()->addDays(3)->setTime(17, 0), ['event_type_id' => 2]],
            [$fdcp, 'Cinemalaya Coordination', $monday->copy()->addDays(2)->setTime(14, 0), $monday->copy()->addDays(2)->setTime(15, 30), ['event_type_id' => 1]],
            [$fdcp, 'Film Restoration Screening', $monday->copy()->addDays(2)->setTime(10, 0), $monday->copy()->addDays(2)->setTime(12, 0), ['event_type_id' => 3]],
            [$fdcp, 'FDCP Anniversary Week', $monday->copy()->addDays(10)->startOfDay(), $monday->copy()->addDays(15)->startOfDay(), ['all_day' => true]],
            [$training, 'Scriptwriting Workshop', $monday->copy()->addDays(5)->setTime(13, 0), $monday->copy()->addDays(5)->setTime(17, 0), ['event_type_id' => 4]],
            [$mis, 'Server Maintenance', $monday->copy()->addDays(4)->setTime(8, 0), $monday->copy()->addDays(4)->setTime(12, 0), []],
            [$mis, 'Sprint Planning', $monday->copy()->addDays(2)->setTime(9, 0), $monday->copy()->addDays(2)->setTime(10, 0), ['event_type_id' => 1]],
            [$personal, 'Dentist', $monday->copy()->addDays(2)->setTime(14, 30), $monday->copy()->addDays(2)->setTime(15, 30), []],
            [$personal, 'Gym', $monday->copy()->addDays(4)->setTime(18, 0), $monday->copy()->addDays(4)->setTime(19, 0), []],
            [$mis, 'Weekly Staff Meeting', $monday->copy()->setTime(10, 0), $monday->copy()->setTime(11, 0), ['event_type_id' => 1, 'rrule' => 'FREQ=WEEKLY']],
        ];

        foreach ($events as [$calendar, $title, $start, $end, $extra]) {
            Event::query()->firstOrCreate(
                ['calendar_id' => $calendar->id, 'title' => $title],
                [
                    'starts_at' => $start->utc(),
                    'ends_at' => $end->utc(),
                    'created_by' => $admin->id,
                    ...$extra,
                ],
            );
        }
    }
}
