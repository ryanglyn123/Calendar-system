<?php

use App\Models\User;

test('labels save as one list and come back to every page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('api.color-labels.update'), [
            'labels' => [
                ['color' => '#d50000', 'name' => '  Urgent ', 'hidden' => false],
                ['color' => '#0B8043', 'name' => '', 'hidden' => true],
                ['color' => '#123456', 'name' => 'Our own', 'hidden' => false],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.0', ['color' => '#D50000', 'name' => 'Urgent', 'hidden' => false])
        ->assertJsonPath('data.1.name', null)
        ->assertJsonPath('data.1.hidden', true);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('auth.user.color_labels', 3)
            ->where('auth.user.color_labels.2.name', 'Our own'));
});

test('someone who never edited labels gets null, so the page uses the palette', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.color_labels', null));
});

test('a color can carry only one label, and colors must be hex', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('api.color-labels.update'), [
            'labels' => [
                ['color' => '#D50000', 'name' => 'Urgent'],
                ['color' => '#d50000', 'name' => 'Again'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('labels.1.color');

    $this->actingAs($user)
        ->putJson(route('api.color-labels.update'), [
            'labels' => [['color' => 'red', 'name' => 'Nope']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('labels.0.color');

    expect($user->refresh()->color_labels)->toBeNull();
});

test('labels are private to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($owner)->putJson(route('api.color-labels.update'), [
        'labels' => [['color' => '#D50000', 'name' => 'Urgent', 'hidden' => false]],
    ])->assertOk();

    $this->actingAs($other)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.color_labels', null));
});
