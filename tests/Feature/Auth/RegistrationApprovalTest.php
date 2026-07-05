<?php

use App\Enums\UserStatus;
use App\Models\Division;
use App\Models\User;
use App\Notifications\UserRegisteredNotification;
use App\Settings\RegistrationSettings;
use Illuminate\Support\Facades\Notification;

test('self-registered users start pending with the staff role', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Pending Person',
        'email' => 'pending@fdcp.gov.ph',
        'division_id' => Division::factory()->create()->id,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect();

    $user = User::query()->where('email', 'pending@fdcp.gov.ph')->firstOrFail();

    expect($user->status)->toBe(UserStatus::Pending)
        ->and($user->hasRole('staff'))->toBeTrue();
});

test('pending users are redirected away from the dashboard', function () {
    $user = User::factory()->pending()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('approval.pending'));
});

test('suspended users are redirected away from the dashboard', function () {
    $user = User::factory()->suspended()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('approval.pending'));
});

test('active users reach the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('users with approve permission are notified about new registrations', function () {
    Notification::fake();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $divisionAdmin = User::factory()->create();
    $divisionAdmin->assignRole('division_admin');

    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->post(route('register.store'), [
        'name' => 'New Registrant',
        'email' => 'newbie@fdcp.gov.ph',
        'division_id' => Division::factory()->create()->id,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    Notification::assertSentTo($superAdmin, UserRegisteredNotification::class);
    Notification::assertSentTo($divisionAdmin, UserRegisteredNotification::class);
    Notification::assertNotSentTo($staff, UserRegisteredNotification::class);
});

test('registration can be limited to an email domain', function () {
    app(RegistrationSettings::class)->fill(['allowed_email_domain' => 'fdcp.gov.ph'])->save();

    $this->post(route('register.store'), [
        'name' => 'Outsider',
        'email' => 'outsider@gmail.com',
        'division_id' => Division::factory()->create()->id,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'outsider@gmail.com')->exists())->toBeFalse();
});

test('registration returns 403 when self-registration is disabled', function () {
    app(RegistrationSettings::class)->fill(['self_registration_enabled' => false])->save();

    $this->post(route('register.store'), [
        'name' => 'Blocked Person',
        'email' => 'blocked@fdcp.gov.ph',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertForbidden();
});
