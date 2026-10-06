<?php

namespace App\Services\Google;

use App\Enums\GoogleSyncError;
use App\Jobs\SyncGoogleCalendar;
use App\Models\GoogleAccount;
use App\Models\GoogleCalendar;
use App\Models\User;
use App\Settings\GoogleCalendarSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * A user's Google connection: linking it, keeping its calendar list
 * current, choosing what to show, and unlinking it cleanly.
 */
class GoogleAccounts
{
    public function __construct(
        private readonly GoogleMirror $mirror,
        private readonly GoogleChannels $channels,
        private readonly GoogleTokens $tokens,
    ) {}

    /** Store (or refresh) the grant from a completed OAuth round trip. */
    public function connect(User $user, SocialiteUser $google): GoogleAccount
    {
        $taken = GoogleAccount::query()
            ->where('google_sub', $google->getId())
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'google' => 'That Google account is already connected to another person here.',
            ]);
        }

        $existing = $user->googleAccount;

        // Switching to a different Google account starts from scratch.
        if ($existing !== null && $existing->google_sub !== $google->getId()) {
            $this->disconnect($existing);
            $existing = null;
        }

        $account = GoogleAccount::updateOrCreate(['user_id' => $user->id], [
            'google_sub' => $google->getId(),
            'email' => $google->getEmail() ?? $user->email,
            'access_token' => $google->token,
            // Google sends a refresh token only with fresh consent; keep the old one otherwise.
            'refresh_token' => filled($google->refreshToken) ? $google->refreshToken : $existing?->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($google->expiresIn ?: 3600)),
            'scopes' => array_values(array_filter($google->approvedScopes)),
            'status' => GoogleAccount::STATUS_ACTIVE,
        ]);

        return $account;
    }

    /**
     * Bring the list of the user's Google calendars up to date, then sync
     * each one being shown. Incremental after the first run.
     */
    public function syncCalendarList(GoogleAccount $account, bool $retryFull = true): void
    {
        if (! $account->isActive()) {
            return;
        }

        $full = $account->calendar_list_sync_token === null;
        $query = $full
            ? ['maxResults' => 250, 'showHidden' => true]
            : ['maxResults' => 250, 'syncToken' => $account->calendar_list_sync_token];

        $items = [];
        $pageToken = null;

        try {
            do {
                $page = GoogleCalendarClient::for($account)
                    ->calendarList($query + ($pageToken ? ['pageToken' => $pageToken] : []));
                array_push($items, ...($page['items'] ?? []));
                $pageToken = $page['nextPageToken'] ?? null;
            } while ($pageToken !== null);
        } catch (GoogleApiException $e) {
            if ($e->fullSyncRequired() && $retryFull) {
                $account->forceFill(['calendar_list_sync_token' => null])->save();
                $this->syncCalendarList($account, retryFull: false);

                return;
            }

            throw $e;
        }

        DB::transaction(function () use ($account, $items, $full, $page) {
            $seen = [];

            foreach ($items as $item) {
                $seen[] = $item['id'];
                $this->applyListEntry($account, $item);
            }

            if ($full) {
                // Calendars that vanished while we were not listening.
                $account->calendars()
                    ->whereNotIn('google_id', $seen)
                    ->where('is_bookings', false)
                    ->get()
                    ->each(fn (GoogleCalendar $calendar) => $this->forget($calendar));
            }

            $account->forceFill([
                'calendar_list_sync_token' => $page['nextSyncToken'] ?? null,
                'last_synced_at' => now(),
            ])->save();
        });

        $account->calendars()
            ->where(fn ($q) => $q->where('sync_enabled', true)->orWhere('is_bookings', true))
            ->pluck('id')
            ->each(fn (int $id) => SyncGoogleCalendar::dispatch($id));
    }

    /** Show or stop showing a Google calendar in the app. */
    public function setSyncEnabled(GoogleCalendar $calendar, bool $enabled): void
    {
        if ($calendar->is_bookings || $calendar->sync_enabled === $enabled) {
            return;
        }

        $calendar->forceFill(['sync_enabled' => $enabled])->save();

        if ($enabled) {
            SyncGoogleCalendar::dispatch($calendar->id);

            return;
        }

        $this->channels->stop($calendar);
        $this->mirror->remove($calendar);
    }

    /**
     * Unlink: stop push channels, revoke the grant at Google, and delete
     * every mirrored calendar, event and token. Booking copies already in
     * the user's Google Calendar stay there; they are the user's now.
     */
    public function disconnect(GoogleAccount $account): void
    {
        $account->loadMissing('calendars', 'user');

        foreach ($account->calendars as $calendar) {
            $this->channels->stop($calendar);
            $this->mirror->remove($calendar);
        }

        $this->channels->stop($account);
        $this->tokens->revoke($account);
        $account->delete();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyListEntry(GoogleAccount $account, array $item): void
    {
        $calendar = $account->calendars()->where('google_id', $item['id'])->first();

        if ($item['deleted'] ?? false) {
            if ($calendar !== null && ! $calendar->is_bookings) {
                $this->forget($calendar);
            }

            return;
        }

        $isHoliday = str_ends_with((string) $item['id'], '#holiday@group.v.calendar.google.com');
        $isBookings = $item['id'] === $account->bookings_calendar_id;
        $attributes = [
            'summary' => $item['summaryOverride'] ?? $item['summary'] ?? $item['id'],
            'time_zone' => $item['timeZone'] ?? null,
            'access_role' => $item['accessRole'] ?? 'reader',
            'background_color' => $item['backgroundColor'] ?? null,
            'is_primary' => (bool) ($item['primary'] ?? false),
            'is_holiday' => $isHoliday,
            'is_bookings' => $isBookings,
        ];

        if ($calendar === null) {
            $account->calendars()->create($attributes + [
                'google_id' => $item['id'],
                'sync_enabled' => $this->showByDefault($item, $isHoliday, $isBookings),
            ]);

            return;
        }

        // Losing or gaining access to details changes what every event
        // looks like: start that calendar over.
        if ($calendar->access_role !== $attributes['access_role']) {
            $attributes['sync_token'] = null;
        }

        $calendar->forceFill($attributes)->save();

        if ($calendar->calendar_id !== null) {
            $this->mirror->ensure($calendar);
        }
    }

    /**
     * New calendars appear here the way they appear in Google: the
     * primary one and those the user keeps ticked there. A holiday
     * calendar the organization already imports would only show twice.
     *
     * @param  array<string, mixed>  $item
     */
    private function showByDefault(array $item, bool $isHoliday, bool $isBookings): bool
    {
        if ($isBookings) {
            return false;
        }

        if ($isHoliday && in_array($item['id'], app(GoogleCalendarSettings::class)->holiday_calendar_ids, true)) {
            return false;
        }

        return (bool) ($item['primary'] ?? false) || (bool) ($item['selected'] ?? false);
    }

    private function forget(GoogleCalendar $calendar): void
    {
        $this->channels->stop($calendar);
        $this->mirror->remove($calendar);
        $calendar->delete();
    }

    /** For the UI: a friendly message for a stored error code. */
    public static function errorMessage(?string $code): ?string
    {
        return $code === null ? null : GoogleSyncError::tryFrom($code)?->message();
    }
}
