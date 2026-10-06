<?php

namespace App\Enums;

enum CalendarType: string
{
    case Organization = 'organization';
    case Division = 'division';
    case Resource = 'resource';
    case Personal = 'personal';
    // A read-only mirror of one of the owner's Google calendars.
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Organization => 'Organization',
            self::Division => 'Division',
            self::Resource => 'Resource',
            self::Personal => 'Personal',
            self::Google => 'Google Calendar',
        };
    }
}
