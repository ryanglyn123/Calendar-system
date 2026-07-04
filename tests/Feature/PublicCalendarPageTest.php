<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;

test('the public calendar page inlines only organization calendars', function () {
    $user = User::factory()->create();
    $org = Calendar::factory()->organization()->create();
    $personal = Calendar::factory()->ownedBy($user)->create();

    $props = $this->actingAs($user)->get(route('calendar.public'))->inertiaProps();

    $calendars = collect($props['initialCalendars']);

    expect($calendars->pluck('id'))->toContain($org->id)
        ->and($calendars->pluck('id'))->not->toContain($personal->id)
        ->and($calendars->pluck('type')->unique()->all())->toBe(['organization']);
});

test('the public calendar window carries organization events only', function () {
    $user = User::factory()->create();
    $org = Calendar::factory()->organization()->create();
    $personal = Calendar::factory()->ownedBy($user)->create();

    $orgEvent = Event::factory()
        ->at(Carbon::now()->addDay()->setTime(10, 0))
        ->create(['calendar_id' => $org->id]);
    $personalEvent = Event::factory()
        ->at(Carbon::now()->addDay()->setTime(12, 0))
        ->create(['calendar_id' => $personal->id]);

    $props = $this->actingAs($user)->get(route('calendar.public'))->inertiaProps();

    $eventIds = collect($props['initialEvents'])->pluck('event_id');

    expect($eventIds)->toContain($orgEvent->id)
        ->and($eventIds)->not->toContain($personalEvent->id);
});
