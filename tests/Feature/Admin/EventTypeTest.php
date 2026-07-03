<?php

use App\Models\EventType;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('admins can create event types', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.event-types.store'), [
            'name' => 'Premiere',
            'color' => '#E200A9',
            'description' => 'Film premieres and red carpets',
        ])
        ->assertRedirect();

    expect(EventType::query()->where('name', 'Premiere')->exists())->toBeTrue();
});

test('event type names must be unique', function () {
    EventType::factory()->create(['name' => 'Meeting']);

    $this->actingAs($this->admin)
        ->post(route('admin.event-types.store'), [
            'name' => 'Meeting',
            'color' => '#000000',
        ])
        ->assertSessionHasErrors('name');
});

test('admins can update and delete event types', function () {
    $type = EventType::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.event-types.update', $type), [
            'name' => 'Renamed Type',
            'color' => '#10B981',
            'is_active' => false,
        ])
        ->assertRedirect();

    $type->refresh();

    expect($type->name)->toBe('Renamed Type')
        ->and($type->is_active)->toBeFalse();

    $this->actingAs($this->admin)
        ->delete(route('admin.event-types.destroy', $type))
        ->assertRedirect();

    expect(EventType::query()->whereKey($type->id)->exists())->toBeFalse();
});

test('staff cannot manage event types', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->get(route('admin.event-types.index'))->assertForbidden();

    $this->actingAs($staff)
        ->post(route('admin.event-types.store'), [
            'name' => 'Nope',
            'color' => '#000000',
        ])
        ->assertForbidden();
});
