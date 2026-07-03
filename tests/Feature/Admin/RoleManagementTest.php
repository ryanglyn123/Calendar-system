<?php

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('an admin can create a role with permissions', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.roles.store'), [
            'name' => 'events_editor',
            'description' => 'Can manage org events',
            'permissions' => ['admin.access', 'events.create-org'],
        ])
        ->assertRedirect();

    $role = Role::query()->where('name', 'events_editor')->firstOrFail();

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['admin.access', 'events.create-org']);
});

test('role names are validated to snake_case and uniqueness', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.roles.store'), [
            'name' => 'Bad Name!',
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->admin)
        ->post(route('admin.roles.store'), [
            'name' => 'staff',
        ])
        ->assertSessionHasErrors('name');
});

test('system role names cannot be renamed but their permissions can change', function () {
    $staffRole = Role::query()->where('name', 'staff')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(route('admin.roles.update', $staffRole), [
            'name' => 'renamed_staff',
            'description' => 'Updated description',
            'permissions' => ['admin.access'],
        ])
        ->assertRedirect();

    $staffRole->refresh();

    expect($staffRole->name)->toBe('staff')
        ->and($staffRole->description)->toBe('Updated description')
        ->and($staffRole->permissions->pluck('name')->all())->toBe(['admin.access']);
});

test('the super_admin role cannot be edited', function () {
    $superRole = Role::query()->where('name', 'super_admin')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(route('admin.roles.update', $superRole), [
            'description' => 'Nope',
            'permissions' => [],
        ])
        ->assertForbidden();
});

test('roles with users cannot be deleted', function () {
    $role = Role::create(['name' => 'occupied_role']);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($this->admin)
        ->delete(route('admin.roles.destroy', $role))
        ->assertForbidden();
});

test('system roles cannot be deleted', function () {
    $staffRole = Role::query()->where('name', 'staff')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('admin.roles.destroy', $staffRole))
        ->assertForbidden();
});

test('empty custom roles can be deleted', function () {
    $role = Role::create(['name' => 'disposable_role']);

    $this->actingAs($this->admin)
        ->delete(route('admin.roles.destroy', $role))
        ->assertRedirect();

    expect(Role::query()->where('name', 'disposable_role')->exists())->toBeFalse();
});
