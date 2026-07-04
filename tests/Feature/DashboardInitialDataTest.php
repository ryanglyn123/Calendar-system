<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Carbon;

test('the dashboard inlines the calendar list and the current event window', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $thisWeek = Event::factory()
        ->at(Carbon::now()->addDay()->setTime(9, 0))
        ->create(['calendar_id' => $calendar->id]);

    $props = $this->actingAs($user)->get(route('dashboard'))->inertiaProps();

    expect(collect($props['initialCalendars'])->pluck('id'))->toContain($calendar->id)
        ->and(collect($props['initialEvents'])->pluck('event_id'))->toContain($thisWeek->id);
});

test('inlined dashboard events never include other users\' personal events', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();

    $secret = Event::factory()
        ->at(Carbon::now()->addDay()->setTime(9, 0))
        ->create(['calendar_id' => $calendar->id]);

    $props = $this->actingAs($other)->get(route('dashboard'))->inertiaProps();

    expect(collect($props['initialEvents'])->pluck('event_id'))->not->toContain($secret->id)
        ->and(collect($props['initialCalendars'])->pluck('id'))->not->toContain($calendar->id);
});

test('the dashboard leaves resource calendars to their section pages', function () {
    $user = User::factory()->create();
    Resource::factory()->create();

    $props = $this->actingAs($user)->get(route('dashboard'))->inertiaProps();

    expect(collect($props['initialCalendars'])->pluck('type'))->not->toContain('resource');
});
