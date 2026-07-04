<?php

use App\Models\Booking;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Support\Carbon;

test('the section page inlines only that type\'s calendars, visible by default', function () {
    $user = User::factory()->create();
    $roomType = ResourceType::factory()->create();
    $room = Resource::factory()->create(['resource_type_id' => $roomType->id]);
    $vanType = ResourceType::factory()->create();
    $van = Resource::factory()->create(['resource_type_id' => $vanType->id]);

    $props = $this->actingAs($user)
        ->get(route('resources.section', $roomType->slug))
        ->inertiaProps();

    $calendars = collect($props['initialCalendars']);

    expect($calendars->pluck('id'))->toContain($room->calendar_id)
        ->and($calendars->pluck('id'))->not->toContain($van->calendar_id)
        ->and($calendars->every(fn ($calendar) => $calendar['is_visible'] === true))->toBeTrue()
        ->and($props['resourceType']['id'])->toBe($roomType->id)
        ->and(collect($props['resources'])->pluck('id'))->toContain($room->id);
});

test('inactive resource types have no section page', function () {
    $user = User::factory()->create();
    $type = ResourceType::factory()->create(['is_active' => false]);

    $this->actingAs($user)
        ->get(route('resources.section', $type->slug))
        ->assertNotFound();
});

test('the window endpoint narrows to one resource type', function () {
    $user = User::factory()->create();
    $roomType = ResourceType::factory()->create();
    $room = Resource::factory()->create(['resource_type_id' => $roomType->id]);
    $personal = Calendar::factory()->ownedBy($user)->create();

    $roomEvent = Event::factory()
        ->at(Carbon::parse('2026-08-10 09:00'))
        ->create(['calendar_id' => $room->calendar_id]);
    $personalEvent = Event::factory()
        ->at(Carbon::parse('2026-08-10 11:00'))
        ->create(['calendar_id' => $personal->id]);

    $ids = collect($this->actingAs($user)->getJson(route('api.events.index', [
        'start' => '2026-08-01T00:00:00Z',
        'end' => '2026-09-01T00:00:00Z',
        'resource_type_id' => $roomType->id,
    ]))->json('data'))->pluck('event_id');

    expect($ids)->toContain($roomEvent->id)
        ->and($ids)->not->toContain($personalEvent->id);
});

test('the window endpoint narrows to calendar types', function () {
    $user = User::factory()->create();
    $org = Calendar::factory()->organization()->create();
    $personal = Calendar::factory()->ownedBy($user)->create();

    $orgEvent = Event::factory()
        ->at(Carbon::parse('2026-08-10 09:00'))
        ->create(['calendar_id' => $org->id]);
    $personalEvent = Event::factory()
        ->at(Carbon::parse('2026-08-10 11:00'))
        ->create(['calendar_id' => $personal->id]);

    $ids = collect($this->actingAs($user)->getJson(route('api.events.index', [
        'start' => '2026-08-01T00:00:00Z',
        'end' => '2026-09-01T00:00:00Z',
        'types' => ['organization'],
    ]))->json('data'))->pluck('event_id');

    expect($ids)->toContain($orgEvent->id)
        ->and($ids)->not->toContain($personalEvent->id);
});

test('my bookings can be filtered to one resource type', function () {
    $user = User::factory()->create();
    $roomType = ResourceType::factory()->create();
    $room = Resource::factory()->create(['resource_type_id' => $roomType->id]);
    $vanType = ResourceType::factory()->create();
    $van = Resource::factory()->create(['resource_type_id' => $vanType->id]);

    $roomBooking = Booking::factory()->create([
        'resource_id' => $room->id,
        'requested_by' => $user->id,
    ]);
    $vanBooking = Booking::factory()->create([
        'resource_id' => $van->id,
        'requested_by' => $user->id,
    ]);

    $ids = collect($this->actingAs($user)->getJson(route('api.bookings.mine', [
        'resource_type_id' => $roomType->id,
    ]))->json('data'))->pluck('id');

    expect($ids)->toContain($roomBooking->id)
        ->and($ids)->not->toContain($vanBooking->id);
});

test('the calendar list endpoint honors the scope filters', function () {
    $user = User::factory()->create();
    $org = Calendar::factory()->organization()->create();
    $roomType = ResourceType::factory()->create();
    $room = Resource::factory()->create(['resource_type_id' => $roomType->id]);

    $types = collect($this->actingAs($user)->getJson(route('api.calendars.index', [
        'types' => ['organization'],
    ]))->json('data'))->pluck('type')->unique()->values();

    expect($types->all())->toBe(['organization']);

    $scoped = collect($this->actingAs($user)->getJson(route('api.calendars.index', [
        'resource_type_id' => $roomType->id,
    ]))->json('data'));

    expect($scoped->pluck('id'))->toContain($room->calendar_id)
        ->and($scoped->pluck('id'))->not->toContain($org->id);
});
