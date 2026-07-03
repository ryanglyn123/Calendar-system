<?php

use App\Enums\SharePermission;
use App\Models\Calendar;
use App\Models\CalendarShare;
use App\Models\User;

test('owners can share their personal calendar', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)
        ->post(route('calendars.shares.store', $calendar), [
            'user_id' => $friend->id,
            'permission' => 'editor',
        ])
        ->assertRedirect();

    expect(CalendarShare::query()
        ->where('calendar_id', $calendar->id)
        ->where('user_id', $friend->id)
        ->value('permission'))->toBe(SharePermission::Editor);
});

test('sharing again updates the permission instead of duplicating', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();

    foreach (['viewer', 'manager'] as $permission) {
        $this->actingAs($owner)->post(route('calendars.shares.store', $calendar), [
            'user_id' => $friend->id,
            'permission' => $permission,
        ]);
    }

    $shares = CalendarShare::query()->where('calendar_id', $calendar->id)->get();

    expect($shares)->toHaveCount(1)
        ->and($shares->first()->permission->value)->toBe('manager');
});

test('non-owners cannot share someone else\'s calendar', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $third = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();

    $this->actingAs($intruder)
        ->post(route('calendars.shares.store', $calendar), [
            'user_id' => $third->id,
            'permission' => 'viewer',
        ])
        ->assertForbidden();
});

test('organization calendars cannot be person-to-person shared', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $someone = User::factory()->create();

    $calendar = Calendar::factory()->organization()->create();

    $this->actingAs($admin)
        ->post(route('calendars.shares.store', $calendar), [
            'user_id' => $someone->id,
            'permission' => 'viewer',
        ])
        ->assertForbidden();
});

test('owners can revoke a share', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();
    $share = CalendarShare::factory()->create([
        'calendar_id' => $calendar->id,
        'user_id' => $friend->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('calendars.shares.destroy', [$calendar, $share]))
        ->assertRedirect();

    expect(CalendarShare::query()->whereKey($share->id)->exists())->toBeFalse();
});

test('a share cannot be revoked through a different calendar', function () {
    $owner = User::factory()->create();
    $calendarA = Calendar::factory()->ownedBy($owner)->create();
    $calendarB = Calendar::factory()->ownedBy($owner)->create();
    $share = CalendarShare::factory()->create(['calendar_id' => $calendarA->id]);

    $this->actingAs($owner)
        ->delete(route('calendars.shares.destroy', [$calendarB, $share]))
        ->assertNotFound();
});
