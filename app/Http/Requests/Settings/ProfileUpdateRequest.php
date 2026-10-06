<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Settings\RegistrationSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);

        // Changing the address must not escape the registration domain policy;
        // an unchanged (possibly legacy) address is always fine.
        $rules['email'][] = function (string $attribute, mixed $value, Closure $fail) {
            $settings = app(RegistrationSettings::class);

            if ($value !== $this->user()->email && ! $settings->emailDomainAllowed((string) $value)) {
                $fail("Email addresses must end with @{$settings->allowed_email_domain}.");
            }
        };

        return $rules;
    }
}
