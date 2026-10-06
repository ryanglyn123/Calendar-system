<?php

use App\Models\Announcement;
use App\Models\Division;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\RegistrationSettings;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('admins can publish and remove announcements', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.announcements.store'), [
            'title' => 'Scheduled maintenance',
            'body' => 'The calendar goes offline Saturday 22:00 for updates.',
            'level' => 'warning',
            'starts_at' => now()->toDateString(),
            'is_dismissible' => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    $announcement = Announcement::query()->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('admin.announcements.destroy', $announcement))
        ->assertRedirect();

    expect(Announcement::query()->count())->toBe(0);
});

test('active announcements reach their audience through shared props', function () {
    $division = Division::factory()->create();
    $member = User::factory()->create(['division_id' => $division->id]);
    $outsider = User::factory()->create();

    Announcement::query()->create([
        'title' => 'Division only',
        'body' => 'Team meeting moved.',
        'level' => 'info',
        'starts_at' => now()->subHour(),
        'division_id' => $division->id,
        'is_dismissible' => true,
        'is_active' => true,
    ]);

    $memberProps = $this->actingAs($member)->get(route('dashboard'))
        ->inertiaProps()['announcements'] ?? [];
    $outsiderProps = $this->actingAs($outsider)->get(route('dashboard'))
        ->inertiaProps()['announcements'] ?? [];

    expect(collect($memberProps)->pluck('title'))->toContain('Division only')
        ->and(collect($outsiderProps)->pluck('title'))->not->toContain('Division only');
});

test('dismissing an announcement hides it for that user only', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $announcement = Announcement::query()->create([
        'title' => 'Org wide',
        'body' => 'Payday moved to Friday.',
        'level' => 'info',
        'starts_at' => now()->subHour(),
        'is_dismissible' => true,
        'is_active' => true,
    ]);

    $this->actingAs($userA)
        ->postJson(route('api.announcements.dismiss', $announcement))
        ->assertNoContent();

    $aProps = $this->actingAs($userA)->get(route('dashboard'))->inertiaProps()['announcements'] ?? [];
    $bProps = $this->actingAs($userB)->get(route('dashboard'))->inertiaProps()['announcements'] ?? [];

    expect(collect($aProps)->pluck('id'))->not->toContain($announcement->id)
        ->and(collect($bProps)->pluck('id'))->toContain($announcement->id);
});

test('non-dismissible announcements refuse dismissal', function () {
    $user = User::factory()->create();

    $announcement = Announcement::query()->create([
        'title' => 'Mandatory notice',
        'body' => 'Building inspection all week.',
        'level' => 'critical',
        'starts_at' => now()->subHour(),
        'is_dismissible' => false,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->postJson(route('api.announcements.dismiss', $announcement))
        ->assertForbidden();
});

test('staff cannot manage announcements or view audit or settings', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->get(route('admin.announcements.index'))->assertForbidden();
    $this->actingAs($staff)->get(route('admin.audit.index'))->assertForbidden();
    $this->actingAs($staff)->get(route('admin.settings.edit'))->assertForbidden();
    $this->actingAs($staff)->get(route('admin.health.index'))->assertForbidden();
});

test('admins can update system settings', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.settings.update'), [
            'general' => [
                'organization_name' => 'Film Development Council of the Philippines',
                'organization_acronym' => 'FDCP',
                'timezone' => 'Asia/Manila',
                'week_starts_on' => 0,
            ],
            'registration' => [
                'self_registration_enabled' => false,
                'allowed_email_domain' => 'fdcp.gov.ph',
                'require_approval' => true,
            ],
        ])
        ->assertRedirect();

    expect(app(GeneralSettings::class)->week_starts_on)->toBe(0)
        ->and(app(RegistrationSettings::class)->self_registration_enabled)->toBeFalse()
        ->and(app(RegistrationSettings::class)->allowed_email_domain)->toBe('fdcp.gov.ph');
});

test('the admin list does not replace the shared banner prop', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    Announcement::query()->create([
        'title' => 'Next week',
        'body' => 'Body',
        'level' => 'info',
        'starts_at' => now()->addWeek(),
        'is_dismissible' => true,
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.announcements.index'))
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->has('announcements', 0));
});
