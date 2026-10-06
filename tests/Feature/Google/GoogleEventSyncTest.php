<?php

use App\Enums\GoogleSyncError;
use App\Models\Event;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use App\Models\User;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleEventSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Google -> app mirroring: full sync, incremental sync with sync tokens,
 * recovery when a token dies, and what each kind of event turns into.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['timezone' => 'Asia/Manila']);
    $this->account = googleAccountFor($this->user);
    $this->calendar = googleCalendarFor($this->account);
});

function googleEventsUrl(): string
{
    return 'www.googleapis.com/calendar/v3/calendars/*/events*';
}

/** Occurrences the window API returns to this user for 19-26 Oct 2026. */
function googleWindowFor(User $user): array
{
    return test()->actingAs($user)
        ->getJson('/api/v1/events?start=2026-10-19T00:00:00%2B08:00&end=2026-10-26T00:00:00%2B08:00&types[]=google')
        ->assertOk()
        ->json('data');
}

test('a full sync mirrors timed, all-day and recurring events, with moved, cancelled and excluded instances', function () {
    Http::fake([googleEventsUrl() => Http::response([
        'items' => [
            googleEventItem('timed1', [
                'description' => '<b>Agenda</b><br>Budget',
                'location' => 'Room 4',
                'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
                'colorId' => '11',
                'organizer' => ['email' => 'boss@example.test', 'displayName' => 'Boss'],
                'attendees' => [
                    ['email' => 'boss@example.test', 'organizer' => true, 'responseStatus' => 'accepted'],
                    ['email' => 'me@example.test', 'self' => true, 'responseStatus' => 'tentative'],
                ],
            ]),
            googleEventItem('allday1', [
                'summary' => 'Offsite',
                'start' => ['date' => '2026-10-21'],
                'end' => ['date' => '2026-10-22'],
            ]),
            googleEventItem('ooo1', [
                'summary' => 'Out of office',
                'eventType' => 'outOfOffice',
                'start' => ['dateTime' => '2026-10-24T13:00:00+08:00'],
                'end' => ['dateTime' => '2026-10-24T17:00:00+08:00'],
            ]),
            googleEventItem('series1', [
                'summary' => 'Standup',
                'recurrence' => ['RRULE:FREQ=DAILY;COUNT=5', 'EXDATE;TZID=Asia/Manila:20261023T090000'],
                'start' => ['dateTime' => '2026-10-20T09:00:00+08:00', 'timeZone' => 'Asia/Manila'],
                'end' => ['dateTime' => '2026-10-20T09:15:00+08:00', 'timeZone' => 'Asia/Manila'],
            ]),
            // Exceptions arrive before their series: the sync must cope.
            googleEventItem('series1_20261021T010000Z', [
                'summary' => 'Standup (moved)',
                'recurringEventId' => 'series1',
                'originalStartTime' => ['dateTime' => '2026-10-21T09:00:00+08:00'],
                'start' => ['dateTime' => '2026-10-21T11:00:00+08:00'],
                'end' => ['dateTime' => '2026-10-21T11:15:00+08:00'],
            ]),
            ['id' => 'series1_20261022T010000Z', 'status' => 'cancelled', 'recurringEventId' => 'series1',
                'originalStartTime' => ['dateTime' => '2026-10-22T09:00:00+08:00']],
        ],
        'nextSyncToken' => 'token-1',
    ])]);

    app(GoogleEventSync::class)->sync($this->calendar);

    expect($this->calendar->fresh())
        ->sync_token->toBe('token-1')
        ->sync_state->toBe('idle')
        ->calendar_id->not->toBeNull();

    // First sync is bounded in time; there was no token to send.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'timeMin=') && ! str_contains($r->url(), 'syncToken='));

    $data = collect(googleWindowFor($this->user));
    $standups = $data->filter(fn ($o) => str_starts_with($o['title'], 'Standup'))->sortBy('starts_at')->values();

    // 20th, 21st moved to 11:00, 22nd cancelled, 23rd excluded, 24th.
    expect($standups->pluck('starts_at')->all())->toBe([
        '2026-10-20T01:00:00+00:00',
        '2026-10-21T03:00:00+00:00',
        '2026-10-24T01:00:00+00:00',
    ]);

    $timed = $data->firstWhere('title', 'Planning');
    expect($timed)
        ->source->toBe('google')
        ->can_edit->toBeFalse()
        ->description->toBe("Agenda\nBudget")
        ->location->toBe('Room 4')
        ->meeting_url->toBe('https://meet.google.com/abc-defg-hij')
        ->color->toBe('#D50000')
        ->and($timed['google']['attendees'])->toHaveCount(2)
        ->and($timed['google']['html_link'])->toContain('timed1');

    expect($data->firstWhere('title', 'Offsite'))
        ->all_day->toBeTrue()
        ->starts_at->toBe('2026-10-20T16:00:00+00:00');

    expect($data->firstWhere('title', 'Out of office')['google']['event_type_label'])->toBe('Out of office');
});

test('incremental syncs send only the sync token and apply edits and deletions', function () {
    $this->calendar->update(['sync_token' => 'token-1']);
    Http::fake([googleEventsUrl() => Http::sequence()
        ->push(['items' => [googleEventItem('a'), googleEventItem('b', ['summary' => 'Gone soon'])], 'nextSyncToken' => 'token-2'])
        ->push(['items' => [googleEventItem('a', ['summary' => 'Renamed', 'etag' => '"2"']), ['id' => 'b', 'status' => 'cancelled']], 'nextSyncToken' => 'token-3']),
    ]);

    $sync = app(GoogleEventSync::class);
    $sync->sync($this->calendar);
    $sync->sync($this->calendar->fresh());

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'syncToken=token-2') && ! str_contains($r->url(), 'timeMin'));

    expect(collect(googleWindowFor($this->user))->pluck('title')->all())->toBe(['Renamed'])
        ->and($this->calendar->fresh()->sync_token)->toBe('token-3');
});

test('a dead sync token (410) triggers a full resync that sweeps away what Google no longer has', function () {
    Http::fake([googleEventsUrl() => Http::sequence()
        ->push(['items' => [googleEventItem('keep'), googleEventItem('stale', ['summary' => 'Stale'])], 'nextSyncToken' => 't1'])
        ->push(['error' => ['code' => 410, 'errors' => [['reason' => 'fullSyncRequired']]]], 410)
        ->push(['items' => [googleEventItem('keep')], 'nextSyncToken' => 't2']),
    ]);

    $sync = app(GoogleEventSync::class);
    $sync->sync($this->calendar);
    $sync->sync($this->calendar->fresh());

    expect(collect(googleWindowFor($this->user))->pluck('title')->all())->toBe(['Planning'])
        ->and($this->calendar->fresh()->sync_token)->toBe('t2');
});

test('syncing the same changes twice never duplicates events', function () {
    Http::fake([googleEventsUrl() => Http::response(['items' => [googleEventItem('once')], 'nextSyncToken' => 't'])]);

    $sync = app(GoogleEventSync::class);
    $sync->sync($this->calendar);
    $this->calendar->update(['sync_token' => null]);
    $sync->sync($this->calendar->fresh());

    expect(GoogleEvent::query()->count())->toBe(1)
        ->and(Event::query()->where('calendar_id', $this->calendar->fresh()->calendar_id)->count())->toBe(1);
});

test('booking copies the app wrote to Google are not mirrored a second time', function () {
    Http::fake([googleEventsUrl() => Http::response(['items' => [
        googleEventItem('fdcpbk00000001', ['extendedProperties' => ['private' => ['fdcpBookingId' => '1']]]),
    ], 'nextSyncToken' => 't'])]);

    app(GoogleEventSync::class)->sync($this->calendar);

    expect(GoogleEvent::query()->count())->toBe(0);
});

test('a free/busy-only calendar shows times as Busy without any details', function () {
    $this->calendar->update(['access_role' => 'freeBusyReader']);
    Http::fake([googleEventsUrl() => Http::response(['items' => [
        googleEventItem('secret', ['summary' => 'Private chat', 'description' => 'nope']),
    ], 'nextSyncToken' => 't'])]);

    app(GoogleEventSync::class)->sync($this->calendar);

    expect(googleWindowFor($this->user)[0])->title->toBe('Busy')->description->toBeNull();
});

test('an expired access token is refreshed before calling Google', function () {
    $this->account->update(['token_expires_at' => now()->subMinute()]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600, 'scope' => implode(' ', $this->account->scopes)]),
        googleEventsUrl() => Http::response(['items' => [], 'nextSyncToken' => 't']),
    ]);

    app(GoogleEventSync::class)->sync($this->calendar);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/events') && $r->hasHeader('Authorization', 'Bearer fresh-token'));
    expect($this->account->fresh()->access_token)->toBe('fresh-token');
});

test('a revoked grant marks the connection for reconnecting and stops there', function () {
    $this->account->update(['token_expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    app(GoogleEventSync::class)->sync($this->calendar);

    expect($this->account->fresh()->status)->toBe(GoogleAccount::STATUS_REAUTH)
        ->and($this->calendar->fresh()->sync_error)->toBe(GoogleSyncError::Reauth->value);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/events'));
});

test('rate limits are recorded and rethrown so the job backs off', function () {
    Http::fake([googleEventsUrl() => Http::response(['error' => ['errors' => [['reason' => 'rateLimitExceeded']]]], 403)]);

    expect(fn () => app(GoogleEventSync::class)->sync($this->calendar))
        ->toThrow(GoogleApiException::class);

    expect($this->calendar->fresh())
        ->sync_state->toBe('error')
        ->sync_error->toBe(GoogleSyncError::RateLimited->value);
});

test('losing access to a calendar removes what was mirrored from it', function () {
    Http::fake([googleEventsUrl() => Http::sequence()
        ->push(['items' => [googleEventItem('x')], 'nextSyncToken' => 't'])
        ->push(['error' => ['code' => 404, 'errors' => [['reason' => 'notFound']]]], 404),
    ]);

    $sync = app(GoogleEventSync::class);
    $sync->sync($this->calendar);
    $sync->sync($this->calendar->fresh());

    expect(googleWindowFor($this->user))->toBe([])
        ->and($this->calendar->fresh()->sync_error)->toBe(GoogleSyncError::NotFound->value);
});

test('mirrored events are read-only for everyone, super admins included', function () {
    Http::fake([googleEventsUrl() => Http::response(['items' => [googleEventItem('ro')], 'nextSyncToken' => 't'])]);
    app(GoogleEventSync::class)->sync($this->calendar);
    $event = Event::query()->where('calendar_id', $this->calendar->fresh()->calendar_id)->firstOrFail();

    $this->actingAs($this->user)->patchJson("/api/v1/events/{$event->id}", ['title' => 'Hacked'])->assertForbidden();
    $this->actingAs($this->user)->deleteJson("/api/v1/events/{$event->id}")->assertForbidden();
    $this->actingAs($this->user)->getJson("/api/v1/events/{$event->id}/comments")->assertNotFound();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin)->deleteJson("/api/v1/events/{$event->id}")->assertForbidden();

    expect($event->fresh()->title)->toBe('Planning');
});

test('one user never sees another user\'s Google calendars or events', function () {
    Http::fake([googleEventsUrl() => Http::response(['items' => [googleEventItem('mine')], 'nextSyncToken' => 't'])]);
    app(GoogleEventSync::class)->sync($this->calendar);
    $mirrorId = $this->calendar->fresh()->calendar_id;

    $other = User::factory()->create();

    expect(googleWindowFor($other))->toBe([]);

    $this->actingAs($other)->getJson('/api/v1/calendars?types[]=google')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($other)->putJson("/api/v1/calendars/{$mirrorId}/preference", ['is_visible' => false])->assertForbidden();
    $this->actingAs($other)
        ->patch("/settings/google-calendar/calendars/{$this->calendar->id}", ['sync_enabled' => false])
        ->assertNotFound();

    // The owner does see it.
    $this->actingAs($this->user)->getJson('/api/v1/calendars?types[]=google')->assertOk()->assertJsonCount(1, 'data');
});
