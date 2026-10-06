<?php

use App\Models\Holiday;
use App\Models\User;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleHolidaySync;
use App\Settings\GoogleCalendarSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Public holidays imported from Google with the server's API key, shown
 * on every calendar, with admins keeping the last word; and free/busy as
 * a booking hint scoped to the caller's own account.
 */
function holidayFeed(array $items): void
{
    Http::fake(['www.googleapis.com/calendar/v3/calendars/*/events*' => Http::response(['items' => $items])]);
}

function holidayItem(string $id, string $date, string $name, string $description): array
{
    return ['id' => $id, 'status' => 'confirmed', 'summary' => $name, 'description' => $description,
        'start' => ['date' => $date], 'end' => ['date' => $date]];
}

beforeEach(function () {
    config(['services.google.calendar_api_key' => 'server-key']);
});

test('holidays are imported with the API key and classified, observances not blocking bookings', function () {
    holidayFeed([
        holidayItem('h1', '2026-12-25', 'Christmas Day', 'Public holiday'),
        holidayItem('h2', '2026-11-02', 'All Souls\' Day', 'Special Non-working Holiday'),
        holidayItem('h3', '2026-02-14', 'Valentine\'s Day', 'Observance'),
    ]);

    app(GoogleHolidaySync::class)->sync();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'key=server-key')
        && str_contains(urldecode($r->url()), 'en.philippines#holiday@group.v.calendar.google.com')
        && ! $r->hasHeader('Authorization'));

    expect(Holiday::query()->where('google_event_id', 'h1')->first())
        ->type->toBe('regular')->blocks_bookings->toBeTrue()->source->toBe('google')
        ->and(Holiday::query()->where('google_event_id', 'h2')->value('type'))->toBe('special_non_working')
        ->and(Holiday::query()->where('google_event_id', 'h3')->first())
        ->type->toBe('observance')->blocks_bookings->toBeFalse()
        ->and(app(GoogleCalendarSettings::class)->holidays_synced_at)->not->toBeNull();
});

test('admin edits and deletions survive later imports; holidays Google dropped go away', function () {
    $first = [
        holidayItem('keep', '2026-12-25', 'Christmas Day', 'Public holiday'),
        holidayItem('edit', '2026-12-30', 'Rizal Day', 'Public holiday'),
        holidayItem('dismiss', '2026-12-24', 'Christmas Eve', 'Special Non-working Holiday'),
        holidayItem('dropped', '2026-12-31', 'Last Day of the Year', 'Special Non-working Holiday'),
    ];
    // Google later withdraws "dropped".
    Http::fake(['www.googleapis.com/calendar/v3/calendars/*/events*' => Http::sequence()
        ->push(['items' => $first])
        ->push(['items' => array_slice($first, 0, 3)]),
    ]);
    app(GoogleHolidaySync::class)->sync();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $edited = Holiday::query()->where('google_event_id', 'edit')->firstOrFail();
    $this->actingAs($admin)->patch("/admin/holidays/{$edited->id}", [
        'name' => 'Rizal Day (moved)', 'date' => '2026-12-29', 'type' => 'regular', 'recurs_annually' => false, 'blocks_bookings' => true,
    ])->assertRedirect();
    $dismissed = Holiday::query()->where('google_event_id', 'dismiss')->firstOrFail();
    $this->actingAs($admin)->delete("/admin/holidays/{$dismissed->id}")->assertRedirect();

    app(GoogleHolidaySync::class)->sync();

    expect($edited->fresh())->name->toBe('Rizal Day (moved)')->overridden->toBeTrue()
        ->and(Holiday::query()->where('google_event_id', 'dismiss')->exists())->toBeFalse()
        ->and(Holiday::query()->where('google_event_id', 'dropped')->exists())->toBeFalse()
        ->and(Holiday::query()->where('google_event_id', 'keep')->exists())->toBeTrue();
});

test('without an API key the import reports that Google is not set up', function () {
    config(['services.google.calendar_api_key' => null]);
    Http::fake();

    expect(fn () => app(GoogleHolidaySync::class)->sync())->toThrow(GoogleApiException::class);
    Http::assertNothingSent();
});

test('holidays show on the calendar, styled apart and never editable', function () {
    Holiday::query()->create(['name' => 'Bonifacio Day', 'date' => '2026-11-30', 'type' => 'regular', 'blocks_bookings' => true]);

    $data = $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/events?start=2026-11-29T00:00:00%2B08:00&end=2026-12-02T00:00:00%2B08:00')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])
        ->source->toBe('holiday')
        ->can_edit->toBeFalse()
        ->all_day->toBeTrue()
        ->booking->toBeNull()
        ->and($data[0]['holiday']['blocks_bookings'])->toBeTrue();
});

test('free/busy merges the caller\'s busy times across calendars, skipping bookings and holidays', function () {
    $user = User::factory()->create();
    $account = googleAccountFor($user);
    googleCalendarFor($account);
    googleCalendarFor($account, ['google_id' => 'team@x', 'is_primary' => false]);
    googleCalendarFor($account, ['google_id' => 'bookings@x', 'is_primary' => false, 'is_bookings' => true]);
    Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => [
        'primary-1@example.test' => ['busy' => [['start' => '2026-10-20T01:00:00Z', 'end' => '2026-10-20T02:00:00Z']]],
        'team@x' => ['busy' => [['start' => '2026-10-20T01:30:00Z', 'end' => '2026-10-20T03:00:00Z']]],
    ]])]);

    $this->actingAs($user)
        ->getJson('/api/v1/google/busy?start=2026-10-20T00:00:00Z&end=2026-10-21T00:00:00Z')
        ->assertOk()
        ->assertJson(['connected' => true, 'data' => [['starts_at' => '2026-10-20T01:00:00Z', 'ends_at' => '2026-10-20T03:00:00Z']]]);

    Http::assertSent(fn (Request $r) => collect($r['items'])->pluck('id')->doesntContain('bookings@x'));
});

test('free/busy only ever answers for the caller', function () {
    googleAccountFor(User::factory()->create());
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/google/busy?start=2026-10-20T00:00:00Z&end=2026-10-21T00:00:00Z')
        ->assertOk()
        ->assertJson(['connected' => false, 'data' => []]);

    Http::assertNothingSent();
});
