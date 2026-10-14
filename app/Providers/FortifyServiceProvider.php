<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Auth\FdcpAccountsProvider;
use App\Models\Division;
use App\Models\User;
use App\Settings\RegistrationSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\LogoutResponse as LocalLogoutResponse;
use Laravel\Socialite\Facades\Socialite;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Logging out also ends the FDCP SSO session; otherwise the next
        // "Sign in with FDCP SSO" signs the same person straight back in.
        $this->app->singleton(LogoutResponse::class, fn () => new class implements LogoutResponse
        {
            public function toResponse($request)
            {
                if (blank(config('services.fdcp_accounts.client_id'))) {
                    return (new LocalLogoutResponse)->toResponse($request);
                }

                return Inertia::location(FdcpAccountsProvider::baseUrl().'/logout/sso?'.http_build_query([
                    'redirect' => route('login'),
                ]));
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();

        Socialite::extend('fdcp_accounts', fn ($app) => Socialite::buildProvider(
            FdcpAccountsProvider::class,
            $app['config']['services.fdcp_accounts'],
        ));
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // Email and password are for administrators only; everyone else
        // signs in through FDCP SSO.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->string(Fortify::username())->value())->first();

            if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
                return null;
            }

            if (! $user->can('admin.access')) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Please sign in with FDCP SSO. Email sign-in is for administrators.',
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => app(RegistrationSettings::class)->self_registration_enabled,
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => ! app(RegistrationSettings::class)->self_registration_enabled
            ? redirect()->route('login')->withErrors(['email' => 'Self-registration is disabled. Ask an administrator for an invitation.'])
            : Inertia::render('auth/Register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'divisions' => Division::query()
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'acronym'])
                    ->toArray(),
            ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
