<?php

use App\Models\Calendar;
use App\Models\CalendarShare;
use App\Models\Division;
use App\Models\User;
use App\Services\Calendar\CalendarAccessService;

function accessibleIdsFor(User $user): array
{
    return app(CalendarAccessService::class)->accessibleCalendarIds($user);
}

test('organization calendars are accessible to every user', function () {
    $calendar = Calendar::factory()->organization()->create();
    $user = User::factory()->create();

    expect(accessibleIdsFor($user))->toContain($calendar->id);
});

test('non-default organization calendars are accessible but hidden by default', function () {
    $calendar = Calendar::factory()->organization(default: false)->create();
    $user = User::factory()->create();

    expect(accessibleIdsFor($user))->toContain($calendar->id)
        ->and($calendar->visibleByDefaultFor($user))->toBeFalse();
});

test('division calendars are only accessible to members of that division', function () {
    $division = Division::factory()->create();
    $otherDivision = Division::factory()->create();

    $calendar = Calendar::query()
        ->where('type', 'division')
        ->where('division_id', $division->id)
        ->firstOrFail();

    $member = User::factory()->create(['division_id' => $division->id]);
    $outsider = User::factory()->create(['division_id' => $otherDivision->id]);

    expect(accessibleIdsFor($member))->toContain($calendar->id)
        ->and(accessibleIdsFor($outsider))->not->toContain($calendar->id);
});

test('personal calendars are isolated between users', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $calendar = Calendar::factory()->ownedBy($owner)->create();

    expect(accessibleIdsFor($owner))->toContain($calendar->id)
        ->and(accessibleIdsFor($other))->not->toContain($calendar->id);

    $access = app(CalendarAccessService::class);

    expect($access->canView($other, $calendar))->toBeFalse()
        ->and($access->canWriteEvents($other, $calendar))->toBeFalse()
        ->and($access->canManage($other, $calendar))->toBeFalse();
});

test('sharing a personal calendar grants access at the given level', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $editor = User::factory()->create();
    $manager = User::factory()->create();

    $calendar = Calendar::factory()->ownedBy($owner)->create();

    CalendarShare::factory()->create(['calendar_id' => $calendar->id, 'user_id' => $viewer->id]);
    CalendarShare::factory()->editor()->create(['calendar_id' => $calendar->id, 'user_id' => $editor->id]);
    CalendarShare::factory()->manager()->create(['calendar_id' => $calendar->id, 'user_id' => $manager->id]);

    $calendar->load('shares');
    $access = app(CalendarAccessService::class);

    expect(accessibleIdsFor($viewer))->toContain($calendar->id)
        ->and($access->canView($viewer, $calendar))->toBeTrue()
        ->and($access->canWriteEvents($viewer, $calendar))->toBeFalse()
        ->and($access->canWriteEvents($editor, $calendar))->toBeTrue()
        ->and($access->canManage($editor, $calendar))->toBeFalse()
        ->and($access->canManage($manager, $calendar))->toBeTrue();
});

test('inactive calendars drop out of the accessible set', function () {
    $calendar = Calendar::factory()->organization()->inactive()->create();
    $user = User::factory()->create();

    expect(accessibleIdsFor($user))->not->toContain($calendar->id);
});

test('resource calendars are accessible to everyone but hidden by default', function () {
    $calendar = Calendar::factory()->resource()->create();
    $user = User::factory()->create();

    expect(accessibleIdsFor($user))->toContain($calendar->id)
        ->and($calendar->visibleByDefaultFor($user))->toBeFalse();

    // Nobody writes events on resource calendars directly — bookings do.
    $access = app(CalendarAccessService::class);
    expect($access->canWriteEvents($user, $calendar))->toBeFalse();
});

test('the calendar list endpoint returns only accessible calendars', function () {
    $user = User::factory()->create();
    $own = Calendar::factory()->ownedBy($user)->create();
    $foreign = Calendar::factory()->create();
    $org = Calendar::factory()->organization()->create();

    $response = $this->actingAs($user)->getJson(route('api.calendars.index'));

    $ids = collect($response->json('data'))->pluck('id');

    $response->assertOk();
    expect($ids)->toContain($own->id)
        ->and($ids)->toContain($org->id)
        ->and($ids)->not->toContain($foreign->id);
});

test('organization events permission is required to write on org calendars', function () {
    $calendar = Calendar::factory()->organization()->create();

    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $divisionAdmin = User::factory()->create();
    $divisionAdmin->assignRole('division_admin');

    $access = app(CalendarAccessService::class);

    expect($access->canWriteEvents($staff, $calendar))->toBeFalse()
        ->and($access->canWriteEvents($divisionAdmin, $calendar))->toBeTrue();
});
