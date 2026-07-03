<?php

use App\Models\User;

test('staff cannot access the admin panel', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->get(route('admin.dashboard'))->assertForbidden();
});

test('super admins can access every admin screen', function (string $routeName) {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get(route($routeName))->assertOk();
})->with([
    'admin.dashboard',
    'admin.users.index',
    'admin.pending-registrations.index',
    'admin.invitations.index',
    'admin.divisions.index',
    'admin.roles.index',
]);

test('division admins can access the admin panel but not division management', function () {
    $admin = User::factory()->create();
    $admin->assignRole('division_admin');

    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('admin.divisions.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.roles.index'))->assertForbidden();
});

test('pending users cannot reach the admin panel even with a role', function () {
    $pending = User::factory()->pending()->create();
    $pending->assignRole('super_admin');

    $this->actingAs($pending)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('approval.pending'));
});

test('guests are redirected to login from admin routes', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});
