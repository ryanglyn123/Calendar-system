<?php

namespace App\Services\Google;

/**
 * The Google OAuth scopes this integration asks for, and why. Each is the
 * narrowest one covering a feature (see docs/google-calendar.md).
 */
final class GoogleScopes
{
    private const BASE = 'https://www.googleapis.com/auth/';

    /** Discover the user's calendars (calendarList.list / watch). */
    public const CALENDAR_LIST = self::BASE.'calendar.calendarlist.readonly';

    /** Read events on the user's calendars, incl. shared and holiday ones (events.list / watch). */
    public const EVENTS_READONLY = self::BASE.'calendar.events.readonly';

    /**
     * Create the separate "Bookings" calendar and write approved bookings
     * only there. The app never edits the user's other calendars.
     */
    public const APP_CREATED = self::BASE.'calendar.app.created';

    /** Busy times across all the user's calendars, for booking hints (freebusy.query). */
    public const FREEBUSY = self::BASE.'calendar.freebusy';

    /** @return list<string> */
    public static function requested(): array
    {
        return [self::CALENDAR_LIST, self::EVENTS_READONLY, self::APP_CREATED, self::FREEBUSY];
    }

    /**
     * What each permission lets the app do, for the settings page.
     *
     * @return list<array{scope: string, label: string}>
     */
    public static function described(): array
    {
        return [
            ['scope' => self::CALENDAR_LIST, 'label' => 'See which Google calendars you have'],
            ['scope' => self::EVENTS_READONLY, 'label' => 'Show your Google events here (read-only)'],
            ['scope' => self::APP_CREATED, 'label' => 'Add your approved bookings to a separate Bookings calendar'],
            ['scope' => self::FREEBUSY, 'label' => 'Warn you about clashes when booking'],
        ];
    }
}
