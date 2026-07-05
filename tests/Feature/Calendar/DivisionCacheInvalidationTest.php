<?php

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
use App\Models\User;
use App\Services\Calendar\CalendarAccessService;

test('moving a user between divisions updates calendar access immediately', function () {
    $divisionA = Division::factory()->create();
    $divisionB = Division::factory()->create();
    $user = User::factory()->create(['division_id' => $divisionA->id]);

    $calendarA = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $divisionA->id)
        ->firstOrFail();
    $calendarB = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $divisionB->id)
        ->firstOrFail();

    $access = app(CalendarAccessService::class);

    // Prime the 5-minute cache the way a real session would.
    expect($access->accessibleCalendarIds($user))->toContain($calendarA->id)
        ->not->toContain($calendarB->id);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->patch(route('admin.users.update', $user), [
        'name' => $user->name,
        'division_id' => $divisionB->id,
        'roles' => ['staff'],
    ])->assertRedirect();

    $ids = $access->accessibleCalendarIds($user->refresh());

    expect($ids)->toContain($calendarB->id)
        ->and($ids)->not->toContain($calendarA->id);
});

test('approving a pending user makes the assigned division visible immediately', function () {
    $division = Division::factory()->create();
    $user = User::factory()->pending()->create(['division_id' => null]);

    $calendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $division->id)
        ->firstOrFail();

    $access = app(CalendarAccessService::class);

    // Prime with the pre-approval (division-less) accessible set.
    expect($access->accessibleCalendarIds($user))->not->toContain($calendar->id);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->post(route('admin.pending-registrations.approve', $user), [
        'division_id' => $division->id,
        'role' => 'staff',
    ])->assertRedirect();

    expect($access->accessibleCalendarIds($user->refresh()))->toContain($calendar->id);
});
