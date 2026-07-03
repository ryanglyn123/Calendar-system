<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.organization_name', 'Film Development Council of the Philippines');
        $this->migrator->add('general.organization_acronym', 'FDCP');
        $this->migrator->add('general.timezone', 'Asia/Manila');
        $this->migrator->add('general.week_starts_on', 1);

        $this->migrator->add('registration.self_registration_enabled', true);
        $this->migrator->add('registration.allowed_email_domain', null);
        $this->migrator->add('registration.require_approval', true);
    }
};
