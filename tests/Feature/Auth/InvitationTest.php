<?php

use App\Enums\UserStatus;
use App\Models\Division;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvitedNotification;
use App\Services\Identity\InvitationService;
use Illuminate\Support\Facades\Notification;

test('admins can issue an invitation which sends an email', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $division = Division::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.invitations.store'), [
            'email' => 'invitee@fdcp.gov.ph',
            'division_id' => $division->id,
            'role' => 'staff',
        ])
        ->assertRedirect();

    expect(Invitation::query()->where('email', 'invitee@fdcp.gov.ph')->exists())->toBeTrue();

    Notification::assertSentOnDemand(
        UserInvitedNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'invitee@fdcp.gov.ph',
    );
});

test('issuing a new invitation revokes the previous open one for the same email', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $service = app(InvitationService::class);

    $first = $service->issue('twice@fdcp.gov.ph', null, null, $admin);
    $second = $service->issue('twice@fdcp.gov.ph', null, null, $admin);

    expect(Invitation::query()->where('email', 'twice@fdcp.gov.ph')->count())->toBe(1)
        ->and(Invitation::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and(Invitation::query()->whereKey($second->id)->exists())->toBeTrue();
});

test('staff cannot issue invitations', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)
        ->post(route('admin.invitations.store'), [
            'email' => 'nope@fdcp.gov.ph',
        ])
        ->assertForbidden();
});

test('a valid invitation can be accepted and yields an active verified user', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $division = Division::factory()->create();
    $roleId = Role::query()->where('name', 'division_admin')->value('id');

    $invitation = Invitation::factory()->create([
        'email' => 'accepted@fdcp.gov.ph',
        'division_id' => $division->id,
        'role_id' => $roleId,
        'invited_by' => $admin->id,
    ]);

    $this->get(route('invitation.show', $invitation->token))->assertOk();

    $this->post(route('invitation.store', $invitation->token), [
        'name' => 'Invited Person',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'accepted@fdcp.gov.ph')->firstOrFail();

    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->division_id)->toBe($division->id)
        ->and($user->hasRole('division_admin'))->toBeTrue()
        ->and($invitation->fresh()->isAccepted())->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('expired or accepted invitations cannot be used', function () {
    $admin = User::factory()->create();

    $expired = Invitation::factory()->expired()->create(['invited_by' => $admin->id]);
    $accepted = Invitation::factory()->accepted()->create(['invited_by' => $admin->id]);

    $this->get(route('invitation.show', $expired->token))->assertRedirect(route('login'));
    $this->get(route('invitation.show', $accepted->token))->assertRedirect(route('login'));

    $this->post(route('invitation.store', $expired->token), [
        'name' => 'Too Late',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('login'));

    expect(User::query()->where('email', $expired->email)->exists())->toBeFalse();
});
