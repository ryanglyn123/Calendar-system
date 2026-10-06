<?php

use App\Models\Calendar;
use App\Models\EventType;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->assignRole('staff');
});

test('users add a private category by name', function () {
    $this->actingAs($this->user)
        ->postJson(route('api.event-categories.store'), ['name' => '  Gym  '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Gym')
        ->assertJsonPath('data.is_personal', true);

    expect(EventType::query()->where('user_id', $this->user->id)->value('name'))->toBe('Gym');
});

test('category names are unique per user, not across users', function () {
    $this->actingAs($this->user)
        ->postJson(route('api.event-categories.store'), ['name' => 'Gym'])
        ->assertCreated();

    $this->actingAs($this->user)
        ->postJson(route('api.event-categories.store'), ['name' => 'Gym'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $other = User::factory()->create();

    $this->actingAs($other)
        ->postJson(route('api.event-categories.store'), ['name' => 'Gym'])
        ->assertCreated();
});

test('only the owner sees and uses their categories', function () {
    $category = EventType::factory()->create(['user_id' => $this->user->id, 'name' => 'Gym']);
    $other = User::factory()->create();
    $other->assignRole('staff');

    $this->actingAs($other)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'eventTypes',
            fn ($types) => collect($types)->doesntContain('id', $category->id),
        ));

    $calendar = Calendar::factory()->ownedBy($other)->create();

    $this->actingAs($other)->postJson(route('api.events.store'), [
        'calendar_id' => $calendar->id,
        'event_type_id' => $category->id,
        'title' => 'Borrowed category',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('event_type_id');

    $own = Calendar::factory()->ownedBy($this->user)->create();

    $this->actingAs($this->user)->postJson(route('api.events.store'), [
        'calendar_id' => $own->id,
        'event_type_id' => $category->id,
        'title' => 'Leg day',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ])->assertCreated()->assertJsonPath('data.event_type.id', $category->id);
});

test('admin event types list leaves out private categories', function () {
    EventType::factory()->create(['user_id' => $this->user->id, 'name' => 'Gym']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get(route('admin.event-types.index'))
        ->assertInertia(fn ($page) => $page->where(
            'eventTypes',
            fn ($types) => collect($types)->doesntContain('name', 'Gym'),
        ));
});
