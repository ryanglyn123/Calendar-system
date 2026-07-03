<?php

use App\Enums\UserStatus;
use App\Models\Division;
use App\Models\User;
use App\Notifications\UserApprovedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('an admin can approve a pending registration with division and role', function () {
    Notification::fake();

    $division = Division::factory()->create();
    $pending = User::factory()->pending()->create();
    $pending->assignRole('staff');

    $this->actingAs($this->admin)
        ->post(route('admin.pending-registrations.approve', $pending), [
            'division_id' => $division->id,
            'role' => 'division_admin',
        ])
        ->assertRedirect();

    $pending->refresh();

    expect($pending->status)->toBe(UserStatus::Active)
        ->and($pending->division_id)->toBe($division->id)
        ->and($pending->approved_by)->toBe($this->admin->id)
        ->and($pending->hasRole('division_admin'))->toBeTrue()
        ->and($pending->hasRole('staff'))->toBeFalse();

    Notification::assertSentTo($pending, UserApprovedNotification::class);
});

test('rejecting a pending registration removes the account', function () {
    $pending = User::factory()->pending()->create();

    $this->actingAs($this->admin)
        ->delete(route('admin.pending-registrations.reject', $pending))
        ->assertRedirect();

    expect(User::withTrashed()->whereKey($pending->id)->exists())->toBeFalse();
});

test('active users cannot be approved again', function () {
    $division = Division::factory()->create();
    $active = User::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.pending-registrations.approve', $active), [
            'division_id' => $division->id,
            'role' => 'staff',
        ])
        ->assertForbidden();
});

test('an admin can suspend and reactivate a user', function () {
    $user = User::factory()->create();
    $user->assignRole('staff');

    $this->actingAs($this->admin)
        ->post(route('admin.users.suspend', $user))
        ->assertRedirect();

    expect($user->refresh()->status)->toBe(UserStatus::Suspended);

    $this->actingAs($this->admin)
        ->post(route('admin.users.reactivate', $user))
        ->assertRedirect();

    expect($user->refresh()->status)->toBe(UserStatus::Active);
});

test('admins cannot suspend themselves or another super admin', function () {
    $otherSuper = User::factory()->create();
    $otherSuper->assignRole('super_admin');

    $this->actingAs($this->admin)
        ->post(route('admin.users.suspend', $this->admin))
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post(route('admin.users.suspend', $otherSuper))
        ->assertForbidden();
});

test('an admin can update a user\'s division and roles', function () {
    $division = Division::factory()->create();
    $user = User::factory()->create();
    $user->assignRole('staff');

    $this->actingAs($this->admin)
        ->patch(route('admin.users.update', $user), [
            'name' => 'Renamed User',
            'division_id' => $division->id,
            'roles' => ['division_admin'],
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->name)->toBe('Renamed User')
        ->and($user->division_id)->toBe($division->id)
        ->and($user->getRoleNames()->all())->toBe(['division_admin']);
});

test('deleting a user soft-deletes the account', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->delete(route('admin.users.destroy', $user))
        ->assertRedirect();

    $this->assertSoftDeleted($user);
});
