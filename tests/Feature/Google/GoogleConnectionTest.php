<?php

use App\Jobs\MaintainGoogleSync;
use App\Jobs\SyncGoogleCalendar;
use App\Jobs\SyncGoogleCalendarList;
use App\Models\Calendar;
use App\Models\GoogleAccount;
use App\Models\GoogleCalendar;
use App\Models\User;
use App\Services\Google\GoogleAccounts;
use App\Services\Google\GoogleChannels;
use App\Services\Google\GoogleEventSync;
use App\Services\Google\GoogleScopes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Connecting, discovering calendars, push notifications, disconnecting.
 */
beforeEach(function () {
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
    ]);
    $this->user = User::factory()->create();
});

function socialiteGoogle(string $id = 'g-1', ?string $refresh = 'refresh-1', ?array $scopes = null): void
{
    $google = (new SocialiteUser)
        ->setToken('access-1')
        ->setRefreshToken($refresh)
        ->setExpiresIn(3600)
        ->setApprovedScopes($scopes ?? ['openid', 'email', ...GoogleScopes::requested()])
        ->map(['id' => $id, 'email' => 'me@example.test', 'name' => 'Me']);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

test('connecting asks Google for offline access and only the scopes the features need', function () {
    $response = $this->actingAs($this->user)->get('/settings/google-calendar/connect');

    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth')
        ->toContain('access_type=offline')
        ->toContain('prompt=consent')
        ->toContain(urlencode('settings/google-calendar/callback'));

    foreach (GoogleScopes::requested() as $scope) {
        expect(urldecode($location))->toContain($scope);
    }

    // Never the broad scopes.
    expect(urldecode($location))->not->toContain('auth/calendar ')->not->toContain('auth/calendar&');
});

test('a successful callback stores the grant encrypted and starts discovering calendars', function () {
    Queue::fake();
    socialiteGoogle();

    $this->actingAs($this->user)->get('/settings/google-calendar/callback?code=abc&state=xyz')
        ->assertRedirect('/settings/google-calendar');

    $account = $this->user->fresh()->googleAccount;
    expect($account)
        ->google_sub->toBe('g-1')
        ->refresh_token->toBe('refresh-1')
        ->scopes->toContain(GoogleScopes::EVENTS_READONLY);

    // At rest the tokens are ciphertext, never the token itself.
    $raw = DB::table('google_accounts')->first();
    expect($raw->access_token)->not->toContain('access-1')
        ->and($raw->refresh_token)->not->toContain('refresh-1');

    Queue::assertPushed(SyncGoogleCalendarList::class);
});

test('denying consent connects nothing and says so', function () {
    $this->actingAs($this->user)->get('/settings/google-calendar/callback?error=access_denied')
        ->assertRedirect('/settings/google-calendar');

    expect(GoogleAccount::query()->count())->toBe(0);
});

test('an expired or forged OAuth state connects nothing', function () {
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->actingAs($this->user)->get('/settings/google-calendar/callback?code=abc&state=bad')
        ->assertRedirect('/settings/google-calendar');

    expect(GoogleAccount::query()->count())->toBe(0);
});

test('one Google account cannot be linked to two people', function () {
    googleAccountFor(User::factory()->create(), ['google_sub' => 'g-1']);
    socialiteGoogle('g-1');

    $this->actingAs($this->user)->get('/settings/google-calendar/callback?code=abc&state=xyz');

    expect($this->user->fresh()->googleAccount)->toBeNull();
});

test('reconnecting keeps the refresh token when Google does not send a new one', function () {
    Queue::fake();
    googleAccountFor($this->user, ['google_sub' => 'g-1', 'refresh_token' => 'kept', 'status' => GoogleAccount::STATUS_REAUTH]);
    socialiteGoogle('g-1', refresh: null);

    $this->actingAs($this->user)->get('/settings/google-calendar/callback?code=abc&state=xyz');

    expect($this->user->fresh()->googleAccount)
        ->refresh_token->toBe('kept')
        ->status->toBe(GoogleAccount::STATUS_ACTIVE);
});

test('partially granted access is recorded so features switch off gracefully', function () {
    Queue::fake();
    socialiteGoogle(scopes: ['openid', GoogleScopes::CALENDAR_LIST, GoogleScopes::EVENTS_READONLY]);

    $this->actingAs($this->user)->get('/settings/google-calendar/callback?code=abc&state=xyz');

    $account = $this->user->fresh()->googleAccount;
    expect($account->canReadEvents())->toBeTrue()
        ->and($account->canPushBookings())->toBeFalse();
});

test('discovery shows the primary calendar and the ones ticked in Google, not duplicate holidays', function () {
    $account = googleAccountFor($this->user);
    Http::fake([
        'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [
                ['id' => 'me@example.test', 'summary' => 'Me', 'primary' => true, 'accessRole' => 'owner', 'backgroundColor' => '#9fe1e7', 'timeZone' => 'Asia/Manila'],
                ['id' => 'team@group.calendar.google.com', 'summary' => 'Team', 'selected' => true, 'accessRole' => 'reader'],
                ['id' => 'old@group.calendar.google.com', 'summary' => 'Old', 'accessRole' => 'writer'],
                ['id' => 'en.philippines#holiday@group.v.calendar.google.com', 'summary' => 'Holidays in the Philippines', 'selected' => true, 'accessRole' => 'reader'],
            ],
            'nextSyncToken' => 'list-1',
        ]),
        'www.googleapis.com/calendar/v3/calendars/*/events*' => Http::response(['items' => [], 'nextSyncToken' => 't']),
    ]);

    app(GoogleAccounts::class)->syncCalendarList($account);

    $shown = GoogleCalendar::query()->where('sync_enabled', true)->pluck('summary')->sort()->values()->all();
    expect($shown)->toBe(['Me', 'Team'])
        ->and(GoogleCalendar::query()->where('is_holiday', true)->value('sync_enabled'))->toBeFalse()
        ->and($account->fresh()->calendar_list_sync_token)->toBe('list-1')
        // Mirrors exist for the shown ones, owned by the user.
        ->and(Calendar::query()->where('type', 'google')->where('owner_id', $this->user->id)->count())->toBe(2);
});

test('a calendar deleted in Google disappears here on the next incremental list sync', function () {
    $account = googleAccountFor($this->user, ['calendar_list_sync_token' => 'list-1']);
    $calendar = googleCalendarFor($account, ['google_id' => 'team@group.calendar.google.com', 'is_primary' => false]);
    Http::fake([
        'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [['id' => 'team@group.calendar.google.com', 'deleted' => true]],
            'nextSyncToken' => 'list-2',
        ]),
    ]);

    app(GoogleAccounts::class)->syncCalendarList($account);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'syncToken=list-1'));
    expect(GoogleCalendar::query()->find($calendar->id))->toBeNull();
});

test('disconnecting revokes the grant and removes every mirrored calendar and event', function () {
    $account = googleAccountFor($this->user);
    $calendar = googleCalendarFor($account);
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/*/events*' => Http::response(['items' => [googleEventItem('e1')], 'nextSyncToken' => 't']),
        'oauth2.googleapis.com/revoke' => Http::response([], 200),
    ]);
    app(GoogleEventSync::class)->sync($calendar);
    $mirrorId = $calendar->fresh()->calendar_id;

    $this->actingAs($this->user)->delete('/settings/google-calendar')->assertRedirect('/settings/google-calendar');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'refresh-'.$this->user->id);
    expect(GoogleAccount::query()->count())->toBe(0)
        ->and(Calendar::withTrashed()->find($mirrorId))->toBeNull()
        ->and(DB::table('events')->where('calendar_id', $mirrorId)->count())->toBe(0);
});

test('turning a calendar off removes its mirror; turning it on syncs it again', function () {
    $account = googleAccountFor($this->user);
    $calendar = googleCalendarFor($account);
    Http::fake(['www.googleapis.com/calendar/v3/calendars/*/events*' => Http::response(['items' => [googleEventItem('e1')], 'nextSyncToken' => 't'])]);
    app(GoogleEventSync::class)->sync($calendar);

    $this->actingAs($this->user)->patch("/settings/google-calendar/calendars/{$calendar->id}", ['sync_enabled' => false]);
    expect($calendar->fresh()->calendar_id)->toBeNull();

    $this->actingAs($this->user)->patch("/settings/google-calendar/calendars/{$calendar->id}", ['sync_enabled' => true]);
    expect($calendar->fresh()->calendar_id)->not->toBeNull()
        ->and(DB::table('events')->where('calendar_id', $calendar->fresh()->calendar_id)->count())->toBe(1);
});

// --- Push notifications ------------------------------------------------

test('a verified change notification queues an incremental sync of that calendar', function () {
    Queue::fake();
    $calendar = googleCalendarFor(googleAccountFor($this->user), [
        'channel_id' => 'chan-1', 'channel_resource_id' => 'res-1', 'channel_token' => hash('sha256', 'secret'),
    ]);

    $headers = ['X-Goog-Channel-ID' => 'chan-1', 'X-Goog-Channel-Token' => 'secret', 'X-Goog-Resource-ID' => 'res-1', 'X-Goog-Resource-State' => 'exists'];

    $this->post('/webhooks/google-calendar', [], $headers)->assertNoContent();
    Queue::assertPushed(SyncGoogleCalendar::class, fn ($job) => $job->googleCalendarId === $calendar->id);
});

test('notifications with a wrong token, an unknown channel or the initial sync ping queue nothing', function () {
    Queue::fake();
    googleCalendarFor(googleAccountFor($this->user), [
        'channel_id' => 'chan-1', 'channel_resource_id' => 'res-1', 'channel_token' => hash('sha256', 'secret'),
    ]);

    $this->post('/webhooks/google-calendar', [], ['X-Goog-Channel-ID' => 'chan-1', 'X-Goog-Channel-Token' => 'guess', 'X-Goog-Resource-ID' => 'res-1', 'X-Goog-Resource-State' => 'exists'])
        ->assertForbidden();
    $this->post('/webhooks/google-calendar', [], ['X-Goog-Channel-ID' => 'nope', 'X-Goog-Resource-State' => 'exists'])
        ->assertNoContent();
    $this->post('/webhooks/google-calendar', [], ['X-Goog-Channel-ID' => 'chan-1', 'X-Goog-Channel-Token' => 'secret', 'X-Goog-Resource-ID' => 'res-1', 'X-Goog-Resource-State' => 'sync'])
        ->assertNoContent();

    Queue::assertNothingPushed();
});

test('upkeep opens or renews channels before they expire and polls quiet calendars', function () {
    config(['services.google.calendar_webhook' => 'https://calendar.example.test/webhooks/google-calendar']);
    Queue::fake();
    $account = googleAccountFor($this->user, ['last_synced_at' => now()]);
    $calendar = googleCalendarFor($account, ['channel_expires_at' => now()->addHours(2), 'channel_id' => 'old', 'channel_resource_id' => 'old-res', 'last_synced_at' => now()->subHours(7)]);
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/*/events/watch' => Http::response(['id' => 'new', 'resourceId' => 'res-9', 'expiration' => (string) now()->addDays(7)->getTimestampMs()]),
        'www.googleapis.com/calendar/v3/users/me/calendarList/watch' => Http::response(['id' => 'list-chan', 'resourceId' => 'list-res', 'expiration' => (string) now()->addDays(7)->getTimestampMs()]),
        'www.googleapis.com/calendar/v3/channels/stop' => Http::response([], 204),
    ]);

    (new MaintainGoogleSync)->handle(app(GoogleChannels::class));

    expect($calendar->fresh())->channel_resource_id->toBe('res-9')->channel_token->not->toBeNull();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/channels/stop') && $r['id'] === 'old');
    Queue::assertPushed(SyncGoogleCalendar::class);
});

test('without a public HTTPS address no channels are opened and calendars are polled instead', function () {
    config(['services.google.calendar_webhook' => 'http://localhost/webhooks/google-calendar']);
    Queue::fake();
    Http::fake();
    $calendar = googleCalendarFor(googleAccountFor($this->user), ['last_synced_at' => now()->subMinutes(15)]);

    (new MaintainGoogleSync)->handle(app(GoogleChannels::class));

    Http::assertNothingSent();
    Queue::assertPushed(SyncGoogleCalendar::class, fn ($job) => $job->googleCalendarId === $calendar->id);
});
