<?php

use App\Models\Calendar;
use App\Models\Division;
use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\CalendarAccessService;
use Illuminate\Support\Carbon;

test('the window endpoint returns occurrences inside the range only', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $inside = Event::factory()->at(Carbon::parse('2026-08-10 09:00'))->create(['calendar_id' => $calendar->id]);
    $before = Event::factory()->at(Carbon::parse('2026-07-10 09:00'))->create(['calendar_id' => $calendar->id]);
    $after = Event::factory()->at(Carbon::parse('2026-09-10 09:00'))->create(['calendar_id' => $calendar->id]);

    $response = $this->actingAs($user)->getJson(route('api.events.index', [
        'start' => '2026-08-01T00:00:00Z',
        'end' => '2026-09-01T00:00:00Z',
    ]));

    $ids = collect($response->json('data'))->pluck('event_id');

    $response->assertOk();
    expect($ids)->toContain($inside->id)
        ->and($ids)->not->toContain($before->id)
        ->and($ids)->not->toContain($after->id);
});

test('multi-day events spanning the window edge are included', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $spanning = Event::factory()->create([
        'calendar_id' => $calendar->id,
        'starts_at' => '2026-07-30 09:00:00',
        'ends_at' => '2026-08-02 17:00:00',
    ]);

    $ids = collect($this->actingAs($user)->getJson(route('api.events.index', [
        'start' => '2026-08-01T00:00:00Z',
        'end' => '2026-09-01T00:00:00Z',
    ]))->json('data'))->pluck('event_id');

    expect($ids)->toContain($spanning->id);
});

test('other users\' personal events never leak through the window endpoint', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();
    $secret = Event::factory()->at(Carbon::parse('2026-08-10 09:00'))->create(['calendar_id' => $calendar->id]);

    $response = $this->actingAs($other)->getJson(route('api.events.index', [
        'start' => '2026-08-01T00:00:00Z',
        'end' => '2026-09-01T00:00:00Z',
        'calendars' => [$calendar->id],
    ]));

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

test('private events are masked as Busy for everyone but the creator', function () {
    $division = Division::factory()->create();
    $creator = User::factory()->create(['division_id' => $division->id]);
    $colleague = User::factory()->create(['division_id' => $division->id]);

    $calendar = Calendar::query()
        ->where('type', 'division')
        ->where('division_id', $division->id)
        ->firstOrFail();

    Event::factory()->private()->at(Carbon::parse('2026-08-10 09:00'))->create([
        'calendar_id' => $calendar->id,
        'created_by' => $creator->id,
        'title' => 'Confidential meeting',
        'location' => 'Secret room',
    ]);

    $window = ['start' => '2026-08-01T00:00:00Z', 'end' => '2026-09-01T00:00:00Z'];

    $asCreator = collect($this->actingAs($creator)->getJson(route('api.events.index', $window))->json('data'))->first();
    $asColleague = collect($this->actingAs($colleague)->getJson(route('api.events.index', $window))->json('data'))->first();

    expect($asCreator['title'])->toBe('Confidential meeting')
        ->and($asColleague['title'])->toBe('Busy')
        ->and($asColleague['location'])->toBeNull()
        ->and($asColleague['is_masked'])->toBeTrue()
        ->and($asColleague['can_edit'])->toBeFalse();
});

test('oversized windows are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('api.events.index', [
        'start' => '2026-01-01T00:00:00Z',
        'end' => '2027-06-01T00:00:00Z',
    ]))->assertStatus(422);
});

test('window queries are bounded to a sane query count', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();
    Event::factory()->count(30)->create(['calendar_id' => $calendar->id, 'starts_at' => '2026-08-10 09:00:00', 'ends_at' => '2026-08-10 10:00:00']);

    // Warm the accessible-calendars cache the way a real session would.
    app(CalendarAccessService::class)->accessibleCalendars($user);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($user)->getJson(route('api.events.index', [
        'start' => '2026-08-01T00:00:00Z',
        'end' => '2026-09-01T00:00:00Z',
    ]))->assertOk();

    expect($queries)->toBeLessThan(15);
});
