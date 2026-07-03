<?php

namespace App\Enums;

enum CalendarType: string
{
    case Organization = 'organization';
    case Division = 'division';
    case Resource = 'resource';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Organization => 'Organization',
            self::Division => 'Division',
            self::Resource => 'Resource',
            self::Personal => 'Personal',
        };
    }
}
