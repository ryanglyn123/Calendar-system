<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $organization_name;

    public string $organization_acronym;

    public string $timezone;

    /** 0 = Sunday, 1 = Monday */
    public int $week_starts_on;

    public static function group(): string
    {
        return 'general';
    }
}
