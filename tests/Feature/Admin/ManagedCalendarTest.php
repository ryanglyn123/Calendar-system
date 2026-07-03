<?php

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('creating a division automatically provisions its calendar', function () {
    $division = Division::factory()->create(['name' => 'Observer Division', 'color' => '#123456']);

    $calendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $division->id)
        ->first();

    expect($calendar)->not->toBeNull()
        ->and($calendar->name)->toBe('Observer Division')
        ->and($calendar->color)->toBe('#123456');
});

test('deactivating a division deactivates its calendar', function () {
    $division = Division::factory()->create();

    $division->update(['is_active' => false]);

    $calendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $division->id)
        ->firstOrFail();

    expect($calendar->is_active)->toBeFalse();
});

test('admins can create an organization calendar', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.calendars.store'), [
            'type' => 'organization',
            'name' => 'Public Holidays',
            'color' => '#DC2626',
            'is_default' => true,
        ])
        ->assertRedirect();

    $calendar = Calendar::query()->where('name', 'Public Holidays')->firstOrFail();

    expect($calendar->type)->toBe(CalendarType::Organization)
        ->and($calendar->is_default)->toBeTrue()
        ->and($calendar->owner_id)->toBeNull();
});

test('division calendars require a division', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.calendars.store'), [
            'type' => 'division',
            'name' => 'Orphan Calendar',
            'color' => '#000000',
        ])
        ->assertSessionHasErrors('division_id');
});

test('admins can update and delete managed calendars', function () {
    $calendar = Calendar::factory()->organization()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.calendars.update', $calendar), [
            'name' => 'Renamed Org Calendar',
            'color' => '#0096E2',
            'is_default' => false,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($calendar->refresh()->name)->toBe('Renamed Org Calendar');

    $this->actingAs($this->admin)
        ->delete(route('admin.calendars.destroy', $calendar))
        ->assertRedirect();

    $this->assertSoftDeleted($calendar);
});

test('personal calendars are invisible to the managed calendar endpoints', function () {
    $calendar = Calendar::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.calendars.update', $calendar), [
            'name' => 'Nope',
            'color' => '#000000',
        ])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete(route('admin.calendars.destroy', $calendar))
        ->assertNotFound();
});

test('staff cannot manage calendars', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->get(route('admin.calendars.index'))->assertForbidden();

    $this->actingAs($staff)
        ->post(route('admin.calendars.store'), [
            'type' => 'organization',
            'name' => 'Nope',
            'color' => '#000000',
        ])
        ->assertForbidden();
});
