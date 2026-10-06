<?php

use App\Models\Invitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    Invitation::factory()->count(2)->create(['invited_by' => $this->admin->id]);
    Invitation::factory()->expired()->create(['email' => 'late@fdcp.gov.ph', 'invited_by' => $this->admin->id]);
    Invitation::factory()->accepted()->create(['invited_by' => $this->admin->id]);
});

test('the invitations list counts open invitations by status', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.invitations.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts', ['all' => 3, 'waiting' => 2, 'expired' => 1])
            ->has('invitations.data', 3));
});

test('the invitations list filters by status', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.invitations.index', ['status' => 'expired']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'expired')
            ->has('invitations.data', 1)
            ->where('invitations.data.0.email', 'late@fdcp.gov.ph'));

    $this->actingAs($this->admin)
        ->get(route('admin.invitations.index', ['status' => 'waiting']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('invitations.data', 2)
            ->where('invitations.data', fn ($rows) => $rows->every(fn ($row) => $row['is_expired'] === false)));

    $this->actingAs($this->admin)
        ->get(route('admin.invitations.index', ['status' => 'accepted']))
        ->assertSessionHasErrors('status');
});
