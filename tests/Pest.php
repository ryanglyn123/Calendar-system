<?php

use App\Models\GoogleAccount;
use App\Models\GoogleCalendar;
use App\Models\User;
use App\Services\Google\GoogleScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
 * Google Calendar test helpers. Google itself is always faked with
 * Http::fake(); these only build the local side of a connection.
 */

/** A connected Google account with a fresh token and every scope granted. */
function googleAccountFor(User $user, array $attributes = []): GoogleAccount
{
    return GoogleAccount::create($attributes + [
        'user_id' => $user->id,
        'google_sub' => 'sub-'.$user->id,
        'email' => "g{$user->id}@example.test",
        'access_token' => 'access-'.$user->id,
        'refresh_token' => 'refresh-'.$user->id,
        'token_expires_at' => now()->addHour(),
        'scopes' => GoogleScopes::requested(),
        'status' => GoogleAccount::STATUS_ACTIVE,
    ]);
}

/** A Google calendar on the account's list, shown in the app by default. */
function googleCalendarFor(GoogleAccount $account, array $attributes = []): GoogleCalendar
{
    return $account->calendars()->create($attributes + [
        'google_id' => 'primary-'.$account->id.'@example.test',
        'summary' => 'Work',
        'time_zone' => 'Asia/Manila',
        'access_role' => 'owner',
        'background_color' => '#039be5',
        'is_primary' => true,
        'sync_enabled' => true,
    ]);
}

/** A Google event resource as events.list returns it. */
function googleEventItem(string $id, array $overrides = []): array
{
    return array_replace_recursive([
        'kind' => 'calendar#event',
        'id' => $id,
        'etag' => '"etag-'.$id.'"',
        'status' => 'confirmed',
        'htmlLink' => "https://www.google.com/calendar/event?eid={$id}",
        'summary' => 'Planning',
        'start' => ['dateTime' => '2026-10-20T09:00:00+08:00', 'timeZone' => 'Asia/Manila'],
        'end' => ['dateTime' => '2026-10-20T10:00:00+08:00', 'timeZone' => 'Asia/Manila'],
        'eventType' => 'default',
    ], $overrides);
}
