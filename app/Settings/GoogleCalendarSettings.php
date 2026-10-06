<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Organization-wide Google Calendar settings: which public holiday
 * calendars feed the holidays list, and how the last import went.
 */
class GoogleCalendarSettings extends Settings
{
    /** @var list<string> Google calendar ids, e.g. en.philippines#holiday@group.v.calendar.google.com */
    public array $holiday_calendar_ids;

    public ?string $holidays_synced_at;

    /** A GoogleSyncError value when the last import failed. */
    public ?string $holidays_sync_error;

    public static function group(): string
    {
        return 'google_calendar';
    }
}
