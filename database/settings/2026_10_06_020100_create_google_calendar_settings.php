<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('google_calendar.holiday_calendar_ids', ['en.philippines#holiday@group.v.calendar.google.com']);
        $this->migrator->add('google_calendar.holidays_synced_at', null);
        $this->migrator->add('google_calendar.holidays_sync_error', null);
    }
};
