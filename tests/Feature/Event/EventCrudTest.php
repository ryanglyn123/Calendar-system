<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;

test('users can create events on their personal calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $response = $this->actingAs($user)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Team huddle',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Team huddle')
        ->assertJsonPath('data.can_edit', true);

    expect(Event::query()->where('calendar_id', $calendar->id)->count())->toBe(1);
});

test('users cannot create events on calendars they cannot write to', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();

    $this->actingAs($intruder)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Sneaky event',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('calendar_id');
});

test('staff cannot write to organization calendars but admins can', function () {
    $calendar = Calendar::factory()->organization()->create();

    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $admin = User::factory()->create();
    $admin->assignRole('division_admin');

    $payload = [
        'calendar_id' => $calendar->id,
        'title' => 'Org event',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ];

    $this->actingAs($staff)->postJson(route('api.events.store'), $payload)
        ->assertUnprocessable();

    $this->actingAs($admin)->postJson(route('api.events.store'), $payload)
        ->assertCreated();
});

test('events validate that the end is after the start', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $this->actingAs($user)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Backwards event',
        'starts_at' => '2026-08-03T10:00:00+08:00',
        'ends_at' => '2026-08-03T09:00:00+08:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
});

test('all-day events are stored end-exclusive at the creator\'s local day boundaries', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Manila']);
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $this->actingAs($user)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Film festival',
        'all_day' => true,
        'starts_at' => '2026-08-03T15:30:00+08:00',
        'ends_at' => '2026-08-05T02:00:00+08:00',
    ])->assertCreated();

    $event = Event::query()->latest('id')->firstOrFail();

    expect($event->starts_at->timezone('Asia/Manila')->format('Y-m-d H:i'))->toBe('2026-08-03 00:00')
        ->and($event->ends_at->timezone('Asia/Manila')->format('Y-m-d H:i'))->toBe('2026-08-06 00:00');
});

test('timed events with an offset are stored normalized to UTC', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Manila']);
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $this->actingAs($user)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Morning huddle',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ])->assertCreated();

    $event = Event::query()->latest('id')->firstOrFail();

    expect($event->starts_at->format('Y-m-d H:i'))->toBe('2026-08-03 01:00')
        ->and($event->starts_at->timezone('Asia/Manila')->format('H:i'))->toBe('09:00');
});

test('owners can update and delete their events', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();
    $event = Event::factory()->create(['calendar_id' => $calendar->id, 'created_by' => $user->id]);

    $this->actingAs($user)->patchJson(route('api.events.update', $event), [
        'title' => 'Updated title',
    ])->assertOk()->assertJsonPath('data.title', 'Updated title');

    $this->actingAs($user)->deleteJson(route('api.events.destroy', $event))
        ->assertNoContent();

    $this->assertSoftDeleted($event);
});

test('users cannot update events on other people\'s calendars', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();
    $event = Event::factory()->create(['calendar_id' => $calendar->id, 'created_by' => $owner->id]);

    $this->actingAs($intruder)->patchJson(route('api.events.update', $event), [
        'title' => 'Hijack',
    ])->assertForbidden();

    $this->actingAs($intruder)->deleteJson(route('api.events.destroy', $event))
        ->assertForbidden();
});

test('event colors resolve event > type > calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create(['color' => '#111111']);
    $type = EventType::factory()->create(['color' => '#222222']);

    $bare = $this->actingAs($user)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Calendar colored',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ])->json('data.color');

    $typed = $this->actingAs($user)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'event_type_id' => $type->id,
        'title' => 'Type colored',
        'starts_at' => '2026-08-03T11:00:00+08:00',
        'ends_at' => '2026-08-03T12:00:00+08:00',
    ])->json('data.color');

    $explicit = $this->actingAs($user)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'event_type_id' => $type->id,
        'color' => '#333333',
        'title' => 'Self colored',
        'starts_at' => '2026-08-03T13:00:00+08:00',
        'ends_at' => '2026-08-03T14:00:00+08:00',
    ])->json('data.color');

    expect($bare)->toBe('#111111')
        ->and($typed)->toBe('#222222')
        ->and($explicit)->toBe('#333333');
});
