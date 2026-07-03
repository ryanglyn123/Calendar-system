<?php

use App\Models\Division;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('an admin can create a division', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.divisions.store'), [
            'name' => 'New Media Division',
            'acronym' => 'NMD',
            'description' => 'Handles new media.',
            'color' => '#0096E2',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(Division::query()->where('name', 'New Media Division')->exists())->toBeTrue();
});

test('division names must be unique', function () {
    Division::factory()->create(['name' => 'Duplicate Division']);

    $this->actingAs($this->admin)
        ->post(route('admin.divisions.store'), [
            'name' => 'Duplicate Division',
            'color' => '#0096E2',
        ])
        ->assertSessionHasErrors('name');
});

test('an admin can update a division', function () {
    $division = Division::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.divisions.update', $division), [
            'name' => 'Renamed Division',
            'acronym' => 'RD',
            'color' => '#580076',
            'is_active' => false,
        ])
        ->assertRedirect();

    $division->refresh();

    expect($division->name)->toBe('Renamed Division')
        ->and($division->is_active)->toBeFalse();
});

test('divisions with members cannot be deleted', function () {
    $division = Division::factory()->create();
    User::factory()->create(['division_id' => $division->id]);

    $this->actingAs($this->admin)
        ->delete(route('admin.divisions.destroy', $division))
        ->assertForbidden();

    expect(Division::query()->whereKey($division->id)->exists())->toBeTrue();
});

test('empty divisions can be deleted', function () {
    $division = Division::factory()->create();

    $this->actingAs($this->admin)
        ->delete(route('admin.divisions.destroy', $division))
        ->assertRedirect();

    $this->assertSoftDeleted($division);
});

test('staff cannot manage divisions', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)
        ->post(route('admin.divisions.store'), [
            'name' => 'Nope',
            'color' => '#000000',
        ])
        ->assertForbidden();
});
