<?php

use App\Models\Calendar;
use App\Models\CalendarUserPreference;
use App\Models\User;

test('users can hide a calendar for themselves only', function () {
    $calendar = Calendar::factory()->organization()->create();
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('api.calendars.preference', $calendar), [
            'is_visible' => false,
        ])
        ->assertOk();

    $userList = $this->actingAs($user)->getJson(route('api.calendars.index'))->json('data');
    $otherList = $this->actingAs($other)->getJson(route('api.calendars.index'))->json('data');

    $userEntry = collect($userList)->firstWhere('id', $calendar->id);
    $otherEntry = collect($otherList)->firstWhere('id', $calendar->id);

    expect($userEntry['is_visible'])->toBeFalse()
        ->and($otherEntry['is_visible'])->toBeTrue();
});

test('users can override a calendar color for themselves', function () {
    $calendar = Calendar::factory()->organization()->create(['color' => '#CC8500']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('api.calendars.preference', $calendar), [
            'color_override' => '#580076',
        ])
        ->assertOk();

    $entry = collect($this->actingAs($user)->getJson(route('api.calendars.index'))->json('data'))
        ->firstWhere('id', $calendar->id);

    expect($entry['effective_color'])->toBe('#580076')
        ->and($entry['color'])->toBe('#CC8500');
});

test('preferences cannot be set on inaccessible calendars', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($owner)->create();

    $this->actingAs($intruder)
        ->putJson(route('api.calendars.preference', $calendar), [
            'is_visible' => true,
        ])
        ->assertForbidden();

    expect(CalendarUserPreference::query()->where('user_id', $intruder->id)->exists())->toBeFalse();
});

test('session JSON routes stay inside the CSRF-protected web group', function (string $routeName) {
    $route = app('router')->getRoutes()->getByName($routeName);

    expect($route->gatherMiddleware())->toContain('web');
})->with([
    'api.calendars.index',
    'api.calendars.preference',
]);
