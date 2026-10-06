<?php

namespace App\Services\Google;

use App\Enums\EventStatus;
use App\Models\GoogleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RRule\RRule;
use Throwable;

/**
 * Translates a Google Calendar event resource into this app's event
 * columns plus the display-only details the app has no columns for.
 * Pure: no database, no HTTP.
 */
class GoogleEventMapper
{
    /**
     * Google's event palette (colorId 1-11) as the Calendar web UI shows it
     * today. colors.get still returns the legacy hexes, which no longer
     * match what users see in Google.
     */
    private const EVENT_COLORS = [
        '1' => '#7986CB', '2' => '#33B679', '3' => '#8E24AA', '4' => '#E67C73',
        '5' => '#F6BF26', '6' => '#F4511E', '7' => '#039BE5', '8' => '#616161',
        '9' => '#3F51B5', '10' => '#0B8043', '11' => '#D50000',
    ];

    /** Readable labels for Google's specialised event types. */
    public const TYPE_LABELS = [
        'birthday' => 'Birthday',
        'focusTime' => 'Focus time',
        'fromGmail' => 'From Gmail',
        'outOfOffice' => 'Out of office',
        'workingLocation' => 'Working location',
    ];

    /**
     * Columns for the mirrored `events` row.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function attributes(array $item, GoogleCalendar $calendar, int $ownerId, string $userTimezone): array
    {
        $busyOnly = $calendar->busyOnly();
        [$start, $end, $allDay, $timezone] = $this->times($item, $calendar, $userTimezone);

        return [
            'calendar_id' => $calendar->calendar_id,
            'created_by' => $ownerId,
            'title' => $busyOnly ? 'Busy' : (Str::limit(trim((string) ($item['summary'] ?? '')), 250, '…') ?: '(No title)'),
            'description' => $busyOnly ? null : $this->plainText($item['description'] ?? null),
            'location' => $busyOnly ? null : (Str::limit((string) ($item['location'] ?? ''), 250, '…') ?: null),
            'meeting_url' => $busyOnly ? null : $this->meetingUrl($item),
            'starts_at' => $start,
            'ends_at' => $end,
            'all_day' => $allDay,
            'timezone' => $timezone,
            'status' => ($item['status'] ?? null) === 'tentative' ? EventStatus::Tentative : EventStatus::Confirmed,
            'color' => self::EVENT_COLORS[(string) ($item['colorId'] ?? '')] ?? null,
            'visibility' => 'default',
            'rrule' => $this->rrule($item, $start, $timezone),
        ];
    }

    /**
     * Display-only details for the quick view.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function details(array $item, GoogleCalendar $calendar): array
    {
        if ($calendar->busyOnly()) {
            return [];
        }

        $person = fn (?array $p) => $p === null ? null : array_filter([
            'email' => $p['email'] ?? null,
            'name' => $p['displayName'] ?? null,
            'self' => (bool) ($p['self'] ?? false),
        ], fn ($value) => $value !== null);

        return array_filter([
            'organizer' => $person($item['organizer'] ?? null),
            'attendees' => collect($this->records($item['attendees'] ?? null))
                ->reject(fn (array $a) => (bool) ($a['resource'] ?? false))
                ->take(50)
                ->map(fn (array $a) => array_filter([
                    'email' => $a['email'] ?? null,
                    'name' => $a['displayName'] ?? null,
                    'response' => $a['responseStatus'] ?? 'needsAction',
                    'organizer' => (bool) ($a['organizer'] ?? false),
                    'self' => (bool) ($a['self'] ?? false),
                    'optional' => (bool) ($a['optional'] ?? false),
                ], fn ($value) => $value !== null))
                ->values()
                ->all() ?: null,
            'attendees_omitted' => (bool) ($item['attendeesOmitted'] ?? false) ?: null,
            'conference' => $this->conferenceName($item),
            'working_location' => $this->workingLocation($item),
        ], fn ($value) => $value !== null);
    }

    /**
     * Whether the event makes the user busy: Google's "Show as busy",
     * minus invitations the user declined.
     *
     * @param  array<string, mixed>  $item
     */
    public function busy(array $item): bool
    {
        if (($item['transparency'] ?? 'opaque') === 'transparent') {
            return false;
        }

        $self = collect($this->records($item['attendees'] ?? null))->firstWhere('self', true);

        return ($self['responseStatus'] ?? null) !== 'declined';
    }

    /**
     * Which slot of its series a modified or cancelled instance replaces.
     *
     * @param  array<string, mixed>  $item
     */
    public function originalStart(array $item, string $userTimezone): ?CarbonImmutable
    {
        $original = $item['originalStartTime'] ?? null;

        if (! is_array($original)) {
            return null;
        }

        return isset($original['date'])
            ? CarbonImmutable::parse($original['date'], $userTimezone)->startOfDay()->utc()
            : CarbonImmutable::parse($original['dateTime'])->utc();
    }

    /**
     * Instances a series skips through EXDATE lines (as opposed to Google's
     * usual cancelled-instance resources), in UTC.
     *
     * @param  array<string, mixed>  $item
     * @return list<CarbonImmutable>
     */
    public function excludedStarts(array $item, string $timezone): array
    {
        $starts = [];

        foreach ($item['recurrence'] ?? [] as $line) {
            if (! preg_match('/^EXDATE([^:]*):(.+)$/i', (string) $line, $match)) {
                continue;
            }

            $zone = preg_match('/TZID=([^;:]+)/i', $match[1], $tz) ? $tz[1] : $timezone;

            foreach (explode(',', $match[2]) as $value) {
                try {
                    $value = trim($value);
                    $starts[] = str_ends_with($value, 'Z')
                        ? CarbonImmutable::parse($value)->utc()
                        : CarbonImmutable::parse($value, $zone)->utc();
                } catch (Throwable) {
                    // An unreadable date skips nothing rather than failing the sync.
                }
            }
        }

        return $starts;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool, 3: string}
     */
    private function times(array $item, GoogleCalendar $calendar, string $userTimezone): array
    {
        $start = $item['start'] ?? [];
        $end = $item['end'] ?? [];

        // All-day events are dates, not instants: pin them to the viewer's
        // midnight so they land on the same day Google shows them.
        if (isset($start['date'])) {
            $from = CarbonImmutable::parse($start['date'], $userTimezone)->startOfDay();
            $to = isset($end['date']) ? CarbonImmutable::parse($end['date'], $userTimezone)->startOfDay() : $from->addDay();

            return [$from->utc(), ($to > $from ? $to : $from->addDay())->utc(), true, $userTimezone];
        }

        $timezone = $start['timeZone'] ?? $calendar->time_zone ?? $userTimezone;
        $from = CarbonImmutable::parse((string) ($start['dateTime'] ?? 'now'))->utc();
        $to = isset($end['dateTime']) ? CarbonImmutable::parse($end['dateTime'])->utc() : $from->addHour();

        return [$from, $to > $from ? $to : $from->addMinutes(15), false, $timezone];
    }

    /**
     * The RRULE line of a recurring event, if the app can expand it.
     * EXDATEs become cancelled overrides; RDATE/EXRULE are not supported
     * and the series then shows its RRULE instances only.
     *
     * @param  array<string, mixed>  $item
     */
    private function rrule(array $item, CarbonImmutable $start, string $timezone): ?string
    {
        foreach ($item['recurrence'] ?? [] as $line) {
            if (! str_starts_with(strtoupper((string) $line), 'RRULE:')) {
                continue;
            }

            $rule = substr((string) $line, 6);

            try {
                new RRule($rule, $start->setTimezone($timezone)->toDateTime());

                return $rule;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The objects in a Google list field, ignoring anything malformed.
     *
     * @return list<array<string, mixed>>
     */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** Google descriptions may carry HTML; the app shows plain text. */
    private function plainText(mixed $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li)>/i'], "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return Str::limit(trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text), 8000, '…');
    }

    /** @param  array<string, mixed>  $item */
    private function meetingUrl(array $item): ?string
    {
        $video = collect($this->records($item['conferenceData']['entryPoints'] ?? null))
            ->firstWhere('entryPointType', 'video');

        $url = $item['hangoutLink'] ?? $video['uri'] ?? null;

        return is_string($url) && strlen($url) <= 255 && str_starts_with($url, 'https://') ? $url : null;
    }

    /** @param  array<string, mixed>  $item */
    private function conferenceName(array $item): ?string
    {
        $name = $item['conferenceData']['conferenceSolution']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /** @param  array<string, mixed>  $item */
    private function workingLocation(array $item): ?string
    {
        $props = $item['workingLocationProperties'] ?? null;

        if (! is_array($props)) {
            return null;
        }

        return match ($props['type'] ?? null) {
            'homeOffice' => 'Home',
            'officeLocation' => $props['officeLocation']['label'] ?? 'Office',
            'customLocation' => $props['customLocation']['label'] ?? 'Elsewhere',
            default => null,
        };
    }
}
