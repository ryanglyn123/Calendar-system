<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class RegistrationSettings extends Settings
{
    public bool $self_registration_enabled;

    /** When set, self-registration and Google sign-in are limited to this email domain (e.g. "fdcp.gov.ph"). */
    public ?string $allowed_email_domain;

    public bool $require_approval;

    public static function group(): string
    {
        return 'registration';
    }

    public function emailDomainAllowed(string $email): bool
    {
        if (blank($this->allowed_email_domain)) {
            return true;
        }

        return str_ends_with(strtolower($email), '@'.strtolower(ltrim($this->allowed_email_domain, '@')));
    }
}
