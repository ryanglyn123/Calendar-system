<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\UserRegisteredNotification;
use App\Settings\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(RegistrationSettings $settings): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in failed. Please try again or use your password.',
            ]);
        }

        $user = User::withTrashed()
            ->where(fn ($query) => $query
                ->where('google_id', $googleUser->getId())
                ->orWhere('email', $googleUser->getEmail()))
            ->first();

        // An archived account still owns its email; creating a twin would hit the unique index.
        if ($user?->trashed()) {
            return redirect()->route('login')->withErrors([
                'email' => 'This account has been deactivated. Ask an administrator to restore it.',
            ]);
        }

        if ($user === null) {
            if (! $settings->self_registration_enabled || ! $settings->emailDomainAllowed($googleUser->getEmail())) {
                return redirect()->route('login')->withErrors([
                    'email' => 'No account exists for this Google email. Ask an administrator for an invitation.',
                ]);
            }

            $user = User::create([
                'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                'email' => $googleUser->getEmail(),
                'password' => Str::password(32),
                'google_id' => $googleUser->getId(),
                'status' => $settings->require_approval ? UserStatus::Pending : UserStatus::Active,
            ]);

            // Google has already verified this address.
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole('staff');

            if ($user->isPending()) {
                Notification::send(User::havingPermission('users.approve'), new UserRegisteredNotification($user));
            }
        } elseif ($user->google_id === null) {
            $user->forceFill(['google_id' => $googleUser->getId()])->save();
        }

        Auth::login($user, remember: true);

        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
