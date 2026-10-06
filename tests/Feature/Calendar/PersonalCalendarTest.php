<?php

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\User;

test('users can create unlimited personal calendars', function () {
    $user = User::factory()->create();

    foreach (['Projects', 'Family', 'Deadlines'] as $name) {
        $this->actingAs($user)
            ->post(route('calendars.store'), [
                'name' => $name,
                'color' => '#0096E2',
            ])
            ->assertRedirect();
    }

    expect(Calendar::query()->where('owner_id', $user->id)->count())->toBe(3)
        ->and(Calendar::query()->where('owner_id', $user->id)->pluck('type')->unique()->all())
        ->toBe([CalendarType::Personal]);
});

test('owners can rename and recolor their calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $this->actingAs($user)
        ->patch(route('calendars.update', $calendar), [
            'name' => 'Renamed',
            'color' => '#E200A9',
        ])
        ->assertRedirect();

    $calendar->refresh();

    expect($calendar->name)->toBe('Renamed')
        ->and($calendar->color)->toBe('#E200A9');
});

test('users cannot update or delete calendars they do not own', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();

    $this->actingAs($intruder)
        ->patch(route('calendars.update', $calendar), [
            'name' => 'Hijacked',
            'color' => '#000000',
        ])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('calendars.destroy', $calendar))
        ->assertForbidden();

    expect($calendar->refresh()->name)->not->toBe('Hijacked');
});

test('owners can delete their personal calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $this->actingAs($user)
        ->delete(route('calendars.destroy', $calendar))
        ->assertRedirect();

    $this->assertSoftDeleted($calendar);
});

test('managed calendars cannot be deleted through the personal endpoint', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $calendar = Calendar::factory()->organization()->create();

    $this->actingAs($admin)
        ->delete(route('calendars.destroy', $calendar))
        ->assertForbidden();
});

test('every user gets one primary personal calendar named after them', function () {
    $user = User::factory()->create(['name' => 'Marco Santos']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $props = $this->actingAs($user)->get(route('dashboard'))->inertiaProps();

    $primary = Calendar::query()
        ->where('owner_id', $user->id)
        ->where('type', CalendarType::Personal->value)
        ->get();

    expect($primary)->toHaveCount(1)
        ->and($primary->first()->name)->toBe('Marco Santos')
        ->and($primary->first()->is_default)->toBeTrue();

    $listed = collect($props['initialCalendars'])->firstWhere('id', $primary->first()->id);

    expect($listed['type'])->toBe('personal')
        ->and($listed['is_visible'])->toBeTrue()
        ->and($listed['can_write_events'])->toBeTrue();
});

test('pages without personal calendars do not provision one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('calendar.public'))->assertOk();

    expect(Calendar::query()->where('owner_id', $user->id)->exists())->toBeFalse();
});

test('the primary personal calendar cannot be deleted', function () {
    $user = User::factory()->create();
    $primary = Calendar::factory()->ownedBy($user)->create(['is_default' => true]);

    $this->actingAs($user)
        ->delete(route('calendars.destroy', $primary))
        ->assertForbidden();

    expect($primary->fresh())->not->toBeNull();
});
