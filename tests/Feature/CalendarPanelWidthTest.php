<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the calendar panel width cookie reaches the page so it paints at that width', function (?string $cookie, ?int $expected) {
    $request = $this->actingAs(User::factory()->create());

    if ($cookie !== null) {
        $request = $request->withUnencryptedCookie('calendar_panel_width', $cookie);
    }

    $request->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('calendarPanelWidth', $expected));
})->with([
    'saved width' => ['312', 312],
    'no cookie' => [null, null],
    'below the minimum' => ['120', null],
    'above the maximum' => ['900', null],
    'not a number' => ['wide', null],
]);

test('a hidden calendar panel stays hidden on the next page load', function (?string $cookie, bool $expected) {
    $request = $this->actingAs(User::factory()->create());

    if ($cookie !== null) {
        $request = $request->withUnencryptedCookie('calendar_panel_hidden', $cookie);
    }

    $request->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('calendarPanelHidden', $expected));
})->with([
    'hidden' => ['1', true],
    'shown' => ['0', false],
    'no cookie' => [null, false],
    'garbage' => ['yes', false],
]);
