<?php

use App\Enums\UserStatus;
use App\Models\Division;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'services.fdcp_accounts.client_id' => 'calendar-client',
        'services.fdcp_accounts.client_secret' => 'secret',
        'services.fdcp_accounts.base_url' => 'https://sso.test',
    ]);
});

/** What fdcp-accounts' /api/userinfo returns, with overrides. */
function ssoReturns(array $claims = []): void
{
    Socialite::shouldReceive('driver->user')->andReturn((new SocialiteUser)->setRaw($claims + [
        'sub' => 'sub-juan',
        'name' => 'Juan Dela Cruz',
        'email' => 'juan@fdcp.gov.ph',
        'department' => null,
        'is_active' => true,
        'apps' => ['calendar' => ['role' => 'staff']],
    ]));
}

test('sign-in goes to the SSO authorize endpoint with PKCE', function () {
    $location = $this->get(route('sso.redirect'))->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://sso.test/oauth/authorize?')
        ->toContain('client_id=calendar-client')
        ->toContain('code_challenge_method=S256');
});

test('sign-in explains itself when SSO is not set up', function () {
    config(['services.fdcp_accounts.client_id' => null]);

    $this->get(route('sso.redirect'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');
});

test('a first SSO sign-in creates an active, verified staff account', function () {
    $division = Division::factory()->create(['acronym' => 'ITU']);
    ssoReturns(['department' => 'ITU']);

    $this->get(route('sso.callback'))->assertRedirect(route('dashboard'));

    $user = User::query()->where('sso_sub', 'sub-juan')->firstOrFail();

    expect($user->name)->toBe('Juan Dela Cruz')
        ->and($user->email)->toBe('juan@fdcp.gov.ph')
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->division_id)->toBe($division->id)
        ->and($user->getRoleNames()->all())->toBe(['staff']);
    $this->assertAuthenticatedAs($user);
});

test('an existing account is adopted by email and keeps its roles', function () {
    $user = User::factory()->create(['email' => 'juan@fdcp.gov.ph', 'name' => 'J. Cruz']);
    $user->assignRole('board_room_approver');
    ssoReturns(['email' => 'JUAN@fdcp.gov.ph']);

    $this->get(route('sso.callback'))->assertRedirect(route('dashboard'));

    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->sso_sub)->toBe('sub-juan')
        ->and($user->name)->toBe('Juan Dela Cruz')
        ->and($user->getRoleNames()->all())->toBe(['board_room_approver']);
    $this->assertAuthenticatedAs($user);
});

test('a linked account follows an email change made in SSO', function () {
    $user = User::factory()->create(['email' => 'old@fdcp.gov.ph', 'sso_sub' => 'sub-juan']);
    ssoReturns(['email' => 'new@fdcp.gov.ph']);

    $this->get(route('sso.callback'))->assertRedirect(route('dashboard'));

    expect($user->refresh()->email)->toBe('new@fdcp.gov.ph');
    $this->assertAuthenticatedAs($user);
});

test('sign-in is refused without a calendar grant or for a disabled SSO account', function (array $claims) {
    ssoReturns($claims);

    $this->get(route('sso.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
})->with([
    'no grant' => [['apps' => ['ticketing' => ['role' => 'staff']]]],
    'disabled' => [['is_active' => false]],
    'no email' => [['email' => null]],
]);

test('an email already linked to another SSO identity is not taken over', function () {
    User::factory()->create(['email' => 'juan@fdcp.gov.ph', 'sso_sub' => 'someone-else']);
    ssoReturns();

    $this->get(route('sso.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    $this->assertGuest();
});

test('a failed SSO round trip lands back on the login page', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new RuntimeException('state mismatch'));

    $this->get(route('sso.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');
});

test('logging out also ends the SSO session', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect('https://sso.test/logout/sso?redirect='.urlencode(route('login')));

    $this->assertGuest();
});
