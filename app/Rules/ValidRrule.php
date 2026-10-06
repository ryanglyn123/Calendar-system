<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RRule\RRule;
use Throwable;

class ValidRrule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The recurrence rule must be a string.');

            return;
        }

        // Sub-daily rules are valid RFC 5545 but expand to the per-window cap every time.
        if (preg_match('/(?:^|;)FREQ=(SECONDLY|MINUTELY|HOURLY)(?:;|$)/i', $value)) {
            $fail('Recurrence must be daily, weekly, monthly or yearly.');

            return;
        }

        try {
            new RRule($value, new \DateTime('2026-01-01 09:00:00'));
        } catch (Throwable) {
            $fail('The recurrence rule is not a valid RFC 5545 RRULE.');
        }
    }
}
