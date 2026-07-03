<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\UserRegisteredNotification;
use App\Settings\RegistrationSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly RegistrationSettings $settings,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        abort_unless($this->settings->self_registration_enabled, 403, 'Self-registration is disabled. Ask an administrator for an invitation.');

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        if (! $this->settings->emailDomainAllowed($input['email'])) {
            throw ValidationException::withMessages([
                'email' => "Registration is limited to @{$this->settings->allowed_email_domain} email addresses.",
            ]);
        }

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'status' => $this->settings->require_approval ? UserStatus::Pending : UserStatus::Active,
        ]);

        $user->assignRole('staff');

        if ($user->isPending()) {
            Notification::send(User::havingPermission('users.approve'), new UserRegisteredNotification($user));
        }

        return $user;
    }
}
