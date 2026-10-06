<?php

namespace App\Services\Event;

use App\Models\Holiday;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The organization's holidays as all-day calendar entries, in the same
 * shape as event occurrences so every view can draw them. They are not
 * events: no record to open, edit or book; `source: holiday` tells the
 * UI to style them apart from events and bookings.
 */
class HolidayOccurrences
{
    public function __construct(private readonly GeneralSettings $settings) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function between(CarbonInterface $start, CarbonInterface $end): array
    {
        $tz = $this->settings->timezone;
        $from = CarbonImmutable::instance($start)->setTimezone($tz)->startOfDay();
        $to = CarbonImmutable::instance($end)->setTimezone($tz);

        $holidays = Holiday::query()
            ->where(fn ($q) => $q
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->orWhere('recurs_annually', true))
            ->orderBy('date')
            ->get();

        $items = [];

        foreach ($holidays as $holiday) {
            foreach ($this->days($holiday, $from, $to) as $day) {
                $items[] = $this->occurrence($holiday, $day);
            }
        }

        return $items;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function days(Holiday $holiday, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $this->settings->timezone;

        if (! $holiday->recurs_annually) {
            return [CarbonImmutable::parse($holiday->date->toDateString(), $tz)];
        }

        $days = [];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $day = CarbonImmutable::create($year, $holiday->date->month, $holiday->date->day, 0, 0, 0, $tz);

            // Feb 29 holidays skip non-leap years rather than sliding to March.
            if ($day->month === $holiday->date->month && $day >= $from && $day < $to) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /** @return array<string, mixed> */
    private function occurrence(Holiday $holiday, CarbonImmutable $day): array
    {
        return [
            'occurrence_id' => "holiday:{$holiday->id}:{$day->toDateString()}",
            'event_id' => 0,
            'calendar_id' => 0,
            'title' => $holiday->name,
            'description' => null,
            'location' => null,
            'meeting_url' => null,
            'starts_at' => $day->utc()->toIso8601String(),
            'ends_at' => $day->addDay()->utc()->toIso8601String(),
            'all_day' => true,
            'status' => 'confirmed',
            // Google's holiday green; holidays are styled apart anyway.
            'color' => '#0B8043',
            'booking' => null,
            'event_type' => null,
            'is_recurring' => false,
            'rrule' => null,
            'attendees' => [],
            'organizer' => null,
            'my_rsvp' => null,
            'my_reminder_minutes' => [],
            'is_private' => false,
            'is_masked' => false,
            'can_edit' => false,
            'created_by' => null,
            'timezone' => $this->settings->timezone,
            'created_at' => null,
            'source' => 'holiday',
            'google' => null,
            'holiday' => [
                'type' => $holiday->type,
                'blocks_bookings' => $holiday->blocks_bookings,
                'from_google' => $holiday->source === 'google',
            ],
        ];
    }
}
