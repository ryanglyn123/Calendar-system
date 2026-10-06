<?php

use App\Models\Event;
use App\Models\Holiday;
use App\Models\NotificationPreference;
use App\Models\ResourceType;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Notifications\UserApprovedNotification;
use App\Settings\GeneralSettings;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/**
 * The admin settings must actually drive the app: these tests pin each
 * General setting to the behaviour it controls.
 */
beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('the organization name and acronym reach every page', function () {
    $settings = app(GeneralSettings::class);
    $settings->organization_name = 'Example Council';
    $settings->organization_acronym = 'EXC';
    $settings->save();

    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organization.name', 'Example Council')
            ->where('organization.acronym', 'EXC'));
});

test('the organization acronym names the calendar in account mail', function () {
    $settings = app(GeneralSettings::class);
    $settings->organization_acronym = 'EXC';
    $settings->save();

    $mail = (new UserApprovedNotification($this->admin))->toMail(User::factory()->create());

    expect($mail->subject)->toBe('Your EXC Calendar account has been approved');
});

test('holiday blocking follows the configured office timezone', function () {
    Holiday::query()->create([
        'name' => 'Wiring holiday',
        'date' => '2026-11-10',
        'recurs_annually' => false,
        'type' => 'regular',
        'blocks_bookings' => true,
    ]);

    // 23:30 UTC on the 10th is already the 11th in Manila, but still the 10th in UTC.
    $start = Carbon::parse('2026-11-10 23:30', 'UTC');
    $end = Carbon::parse('2026-11-11 00:30', 'UTC');

    $settings = app(GeneralSettings::class);

    $settings->timezone = 'Asia/Manila';
    $settings->save();
    expect(Holiday::blockingWithin($start, $end))->toBeNull();

    $settings->timezone = 'UTC';
    $settings->save();
    expect(Holiday::blockingWithin($start, $end)?->name)->toBe('Wiring holiday');
});

test('the sidebar resource sections survive pages that pass their own resourceTypes prop', function () {
    ResourceType::factory()->create(['is_active' => true]);

    $this->actingAs($this->admin)
        ->get(route('admin.resources.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('resourceSections.0.slug')
            ->has('resourceTypes'));
});

test('the event reminder preference switches reminder channels off', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create(['created_by' => $user->id]);

    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'notification_key' => EventReminderNotification::KEY,
        'mail_enabled' => false,
        'database_enabled' => true,
    ]);

    $mail = new EventReminderNotification($event, now()->addHour(), 'mail');
    $inApp = new EventReminderNotification($event, now()->addHour(), 'database');

    expect($mail->via($user))->toBe([])
        ->and($inApp->via($user))->toBe(['database']);
});

test('saving system settings leaves an audit log entry', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.settings.update'), [
            'general' => [
                'organization_name' => 'Example Council',
                'organization_acronym' => 'EXC',
                'timezone' => 'Asia/Manila',
                'week_starts_on' => 1,
            ],
            'registration' => [
                'self_registration_enabled' => true,
                'allowed_email_domain' => null,
                'require_approval' => true,
            ],
        ])
        ->assertRedirect();

    $entry = Activity::query()->where('event', 'settings-updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties['attributes']['general']['organization_acronym'])->toBe('EXC');
});
