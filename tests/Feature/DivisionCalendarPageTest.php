<?php

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;

test('the division calendar page inlines only the user\'s own division calendars', function () {
    $division = Division::factory()->create();
    $otherDivision = Division::factory()->create();
    $user = User::factory()->create(['division_id' => $division->id]);

    $org = Calendar::factory()->organization()->create();
    $personal = Calendar::factory()->ownedBy($user)->create();

    $ownCalendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $division->id)
        ->firstOrFail();
    $otherCalendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $otherDivision->id)
        ->firstOrFail();

    $props = $this->actingAs($user)->get(route('calendar.division'))->inertiaProps();

    $calendars = collect($props['initialCalendars']);

    expect($calendars->pluck('id'))->toContain($ownCalendar->id)
        ->and($calendars->pluck('id'))->not->toContain($otherCalendar->id)
        ->and($calendars->pluck('id'))->not->toContain($org->id)
        ->and($calendars->pluck('id'))->not->toContain($personal->id)
        ->and($calendars->pluck('type')->unique()->all())->toBe(['division'])
        ->and($props['divisionName'])->toBe($division->name);
});

test('the division calendar window carries division events only', function () {
    $division = Division::factory()->create();
    $user = User::factory()->create(['division_id' => $division->id]);
    $personal = Calendar::factory()->ownedBy($user)->create();

    $divisionCalendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $division->id)
        ->firstOrFail();

    $divisionEvent = Event::factory()
        ->at(Carbon::now()->addDay()->setTime(10, 0))
        ->create(['calendar_id' => $divisionCalendar->id]);
    $personalEvent = Event::factory()
        ->at(Carbon::now()->addDay()->setTime(12, 0))
        ->create(['calendar_id' => $personal->id]);

    $props = $this->actingAs($user)->get(route('calendar.division'))->inertiaProps();

    $eventIds = collect($props['initialEvents'])->pluck('event_id');

    expect($eventIds)->toContain($divisionEvent->id)
        ->and($eventIds)->not->toContain($personalEvent->id);
});

test('users without a division are redirected to the dashboard', function () {
    $user = User::factory()->create(['division_id' => null]);

    $this->actingAs($user)
        ->get(route('calendar.division'))
        ->assertRedirect(route('dashboard'));
});

test('any division member can create events on the division calendar', function () {
    $division = Division::factory()->create();
    $member = User::factory()->create(['division_id' => $division->id]);
    $member->assignRole('staff');

    $divisionCalendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $division->id)
        ->firstOrFail();

    $this->actingAs($member)->postJson(route('api.events.store'), [
        'calendar_id' => $divisionCalendar->id,
        'title' => 'Division sync',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ])->assertCreated()->assertJsonPath('data.can_edit', true);
});
