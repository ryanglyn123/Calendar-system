<?php

namespace App\Http\Controllers\Settings;

use App\Enums\BookingStatus;
use App\Enums\GoogleSyncError;
use App\Http\Controllers\Controller;
use App\Jobs\PushBookingToGoogle;
use App\Jobs\SyncGoogleCalendarList;
use App\Models\Booking;
use App\Models\GoogleAccount;
use App\Models\GoogleCalendar;
use App\Services\Google\GoogleAccounts;
use App\Services\Google\GoogleChannels;
use App\Services\Google\GoogleScopes;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * The user's Google Calendar connection: connect (OAuth), pick which
 * calendars show here, the bookings copy, sync status, disconnect.
 * Everything is scoped to the signed-in user's own account.
 */
class GoogleCalendarController extends Controller
{
    public function __construct(private readonly GoogleAccounts $accounts) {}

    public function edit(Request $request): Response
    {
        $account = $request->user()->googleAccount;

        return Inertia::render('settings/GoogleCalendar', [
            'configured' => self::configured(),
            'permissions' => GoogleScopes::described(),
            'account' => $account === null ? null : [
                'email' => $account->email,
                'needs_reconnect' => ! $account->isActive(),
                'granted' => array_values(array_filter(GoogleScopes::requested(), fn (string $s) => $account->granted($s))),
                'push_bookings' => $account->push_bookings,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                'push_notifications' => GoogleChannels::available(),
            ],
            'calendars' => $account === null ? [] : $account->calendars()
                ->where('is_bookings', false)
                ->orderByDesc('is_primary')
                ->orderBy('is_holiday')
                ->orderBy('summary')
                ->get()
                ->map(fn (GoogleCalendar $calendar) => [
                    'id' => $calendar->id,
                    'summary' => $calendar->summary,
                    'color' => $calendar->background_color,
                    'access_role' => $calendar->access_role,
                    'is_primary' => $calendar->is_primary,
                    'is_holiday' => $calendar->is_holiday,
                    'sync_enabled' => $calendar->sync_enabled,
                    'last_synced_at' => $calendar->last_synced_at?->toIso8601String(),
                    'error' => GoogleAccounts::errorMessage($calendar->sync_error),
                ])
                ->values(),
        ]);
    }

    /** Start (or redo) the OAuth consent round trip. */
    public function redirect(Request $request): SymfonyRedirect|RedirectResponse
    {
        if (! self::configured()) {
            Toast::error(GoogleSyncError::NotConfigured->message());

            return to_route('google-calendar.edit');
        }

        return $this->provider()
            ->scopes(GoogleScopes::requested())
            ->with([
                // A refresh token for background syncing, even on reconnect.
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
                'login_hint' => $request->user()->googleAccount->email ?? $request->user()->email,
            ])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            Toast::info($request->input('error') === 'access_denied'
                ? 'Google Calendar was not connected: access was not granted.'
                : 'Google could not complete the connection. Please try again.');

            return to_route('google-calendar.edit');
        }

        try {
            $google = $this->provider()->user();
        } catch (InvalidStateException) {
            Toast::error('The connection attempt expired. Please try again.');

            return to_route('google-calendar.edit');
        } catch (Throwable $e) {
            report($e);
            Toast::error('Google could not complete the connection. Please try again.');

            return to_route('google-calendar.edit');
        }

        // The OAuth 2 provider always yields an OAuth 2 user (with tokens).
        if (! $google instanceof SocialiteUser) {
            Toast::error('Google could not complete the connection. Please try again.');

            return to_route('google-calendar.edit');
        }

        try {
            $account = $this->accounts->connect($request->user(), $google);
        } catch (ValidationException $e) {
            Toast::error($e->getMessage());

            return to_route('google-calendar.edit');
        }

        SyncGoogleCalendarList::dispatch($account->id);

        $missing = array_diff(GoogleScopes::requested(), $account->scopes);

        $missing === []
            ? Toast::success('Google Calendar connected. Your calendars will appear in a moment.')
            : Toast::warning('Connected, but some permissions were not granted, so some features are off.');

        return to_route('google-calendar.edit');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['push_bookings' => ['required', 'boolean']]);
        $account = $this->account($request);

        $account->update($validated);

        // Turning it on copies existing approved bookings too.
        if ($validated['push_bookings']) {
            Booking::query()
                ->where('requested_by', $account->user_id)
                ->where('status', BookingStatus::Approved->value)
                ->where('ends_at', '>', now())
                ->pluck('id')
                ->each(fn (int $id) => PushBookingToGoogle::dispatch($id));
        }

        Toast::success('Saved.');

        return back();
    }

    public function updateCalendar(Request $request, int $calendar): RedirectResponse
    {
        $validated = $request->validate(['sync_enabled' => ['required', 'boolean']]);

        // Looked up through the user's own account: another user's id is a 404.
        $googleCalendar = $this->account($request)->calendars()->where('is_bookings', false)->findOrFail($calendar);

        $this->accounts->setSyncEnabled($googleCalendar, $validated['sync_enabled']);

        return back();
    }

    public function sync(Request $request): RedirectResponse
    {
        $account = $this->account($request);

        if (! $account->isActive()) {
            Toast::error(GoogleSyncError::Reauth->message());

            return back();
        }

        SyncGoogleCalendarList::dispatch($account->id);
        Toast::info('Syncing with Google Calendar…');

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->accounts->disconnect($this->account($request));

        Toast::success('Google Calendar disconnected. Its calendars were removed from here.');

        return to_route('google-calendar.edit');
    }

    public static function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function account(Request $request): GoogleAccount
    {
        $account = $request->user()->googleAccount;

        abort_if($account === null, 404);

        return $account;
    }

    private function provider(): GoogleProvider
    {
        /** @var GoogleProvider $provider */
        $provider = Socialite::driver('google');

        return $provider->redirectUrl(url()->to(config()->string('services.google.calendar_redirect')));
    }
}
