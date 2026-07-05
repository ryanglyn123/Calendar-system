<?php

namespace App\Support;

use App\Enums\CalendarType;
use App\Models\Event;
use App\Models\Resource;

/**
 * A deep link to an event on the calendar it lives on. The calendar page
 * reads ?event=ID&date=YYYY-MM-DD to open that event's quick view.
 */
class EventLink
{
    public static function url(Event $event): string
    {
        $path = match ($event->calendar->type) {
            CalendarType::Organization => route('calendar.public', absolute: false),
            CalendarType::Division => route('calendar.division', absolute: false),
            CalendarType::Resource => self::resourceSectionPath($event),
            CalendarType::Personal => route('dashboard', absolute: false),
        };

        return $path.'?event='.$event->id
            .'&date='.$event->starts_at->timezone($event->timezone)->toDateString();
    }

    private static function resourceSectionPath(Event $event): string
    {
        $resource = Resource::query()
            ->with('type')
            ->where('calendar_id', $event->calendar_id)
            ->first();

        return $resource?->type !== null
            ? route('resources.section', $resource->type, absolute: false)
            : route('dashboard', absolute: false);
    }
}
