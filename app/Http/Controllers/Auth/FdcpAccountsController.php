<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Sign-in through FDCP SSO (fdcp-accounts). The SSO decides who may use the
 * calendar (an "apps.calendar" grant); the calendar keeps its own roles.
 */
class FdcpAccountsController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        if (blank(config('services.fdcp_accounts.client_id'))) {
            return $this->failure('FDCP SSO is not set up yet. Please contact IT support.');
        }

        return Socialite::driver('fdcp_accounts')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $account = Socialite::driver('fdcp_accounts')->user();
            $claims = $account instanceof SocialiteUser ? $account->getRaw() : [];
        } catch (Throwable $e) {
            report($e);

            return $this->failure('Sign-in did not finish. Please try again.');
        }

        $sub = $claims['sub'] ?? null;
        $email = is_string($claims['email'] ?? null) ? Str::lower($claims['email']) : null;

        if (! isset($claims['apps']['calendar']) || ($claims['is_active'] ?? true) === false
            || ! is_string($sub) || $sub === '' || $email === null) {
            return $this->failure("Your FDCP account doesn't have access to the Calendar yet. Request it from your FDCP account page.");
        }

        // Linked accounts match on the SSO id; existing accounts (roster,
        // invitations) are adopted by email on their first SSO sign-in.
        $user = User::withTrashed()->where('sso_sub', $sub)->first()
            ?? User::withTrashed()->whereNull('sso_sub')->where('email', $email)->first();

        if ($user?->trashed()) {
            return $this->failure('This account has been deactivated. Ask an administrator to restore it.');
        }

        $emailTaken = User::withTrashed()
            ->where('email', $email)
            ->when($user, fn ($query) => $query->whereKeyNot($user->id))
            ->exists();

        if ($emailTaken) {
            return $this->failure('Your email is linked to a different calendar account. Please contact IT support.');
        }

        // A new person: the SSO grant is the approval.
        $user ??= (new User)->forceFill([
            'password' => Str::password(32),
            'status' => UserStatus::Active,
            'approved_at' => now(),
            'division_id' => $this->divisionFor($claims['department'] ?? null),
        ]);

        // The SSO owns name and email, and has verified the address.
        $user->forceFill([
            'sso_sub' => $sub,
            'name' => is_string($claims['name'] ?? null) && $claims['name'] !== '' ? $claims['name'] : $email,
            'email' => $email,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        if ($user->wasRecentlyCreated) {
            $user->assignRole('staff');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function divisionFor(mixed $department): ?int
    {
        if (! is_string($department) || trim($department) === '') {
            return null;
        }

        return Division::query()
            ->where('name', trim($department))
            ->orWhere('acronym', trim($department))
            ->value('id');
    }

    private function failure(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['sso' => $message]);
    }
}
