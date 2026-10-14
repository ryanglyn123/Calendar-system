<?php

/**
 * Regression tests for the issues found in WEBSITE_AUDIT.md. Each test is
 * tagged with the audit issue id it covers.
 */

use App\Enums\BookingStatus;
use App\Enums\CalendarType;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Calendar;
use App\Models\Division;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Holiday;
use App\Models\Invitation;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Calendar\CalendarAccessService;
use App\Settings\RegistrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Activitylog\Models\Activity;

function auditUserWith(string ...$permissions): User
{
    $role = Role::create(['name' => 'audit_'.fake()->unique()->lexify('??????')]);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ---------------------------------------------------------------- ISS-001

test('ISS-001: approving a registration cannot grant super_admin without being one', function () {
    $approver = auditUserWith('admin.access', 'users.approve');
    $pending = User::factory()->pending()->create();
    $division = Division::factory()->create();

    $this->actingAs($approver)
        ->postJson(route('admin.pending-registrations.approve', $pending), [
            'division_id' => $division->id,
            'role' => 'super_admin',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');

    expect($pending->refresh()->isPending())->toBeTrue()
        ->and($pending->hasRole('super_admin'))->toBeFalse();
});

test('ISS-001: a super admin can still approve someone as super_admin', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $pending = User::factory()->pending()->create();
    $division = Division::factory()->create();

    $this->actingAs($superAdmin)
        ->post(route('admin.pending-registrations.approve', $pending), [
            'division_id' => $division->id,
            'role' => 'super_admin',
        ])
        ->assertRedirect();

    expect($pending->refresh()->hasRole('super_admin'))->toBeTrue();
});

test('ISS-001: a user manager cannot assign super_admin through the user editor', function () {
    $manager = auditUserWith('admin.access', 'users.manage');
    $target = User::factory()->create();

    $this->actingAs($manager)
        ->patchJson(route('admin.users.update', $target), [
            'name' => $target->name,
            'division_id' => null,
            'roles' => ['super_admin'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('roles.0');

    expect($target->refresh()->hasRole('super_admin'))->toBeFalse();
});

test('ISS-001: a user manager cannot edit a super admin account', function () {
    $manager = auditUserWith('admin.access', 'users.manage');
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($manager)
        ->patchJson(route('admin.users.update', $superAdmin), [
            'name' => 'Demoted',
            'division_id' => null,
            'roles' => ['staff'],
        ])
        ->assertForbidden();

    expect($superAdmin->refresh()->hasRole('super_admin'))->toBeTrue();
});

test('ISS-001: a user manager cannot invite someone as super_admin', function () {
    Notification::fake();
    $manager = auditUserWith('admin.access', 'users.manage');

    $this->actingAs($manager)
        ->postJson(route('admin.invitations.store'), [
            'email' => 'new@fdcp.gov.ph',
            'role' => 'super_admin',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');

    expect(Invitation::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- ISS-002

test('ISS-002: bookings on an archived resource still render for the requester and the admin desk', function () {
    $requester = User::factory()->create();
    $resource = Resource::factory()->create();
    $booking = Booking::factory()->create([
        'resource_id' => $resource->id,
        'requested_by' => $requester->id,
        'status' => BookingStatus::Pending,
    ]);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->delete(route('admin.resources.destroy', $resource))
        ->assertRedirect();

    expect($resource->refresh()->trashed())->toBeTrue();

    $this->actingAs($requester)
        ->getJson(route('api.bookings.mine'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $booking->id)
        ->assertJsonPath('data.0.resource.name', $resource->name);

    $this->actingAs($superAdmin)
        ->get(route('admin.dashboard'))
        ->assertOk();

    $this->actingAs($superAdmin)
        ->get(route('admin.bookings.index'))
        ->assertOk();
});

// ---------------------------------------------------------------- ISS-003

test('ISS-003: a change request cannot exceed the resource capacity', function () {
    $requester = User::factory()->create();
    $resource = Resource::factory()->create(['capacity' => 10]);
    $booking = Booking::factory()->approved()->create([
        'resource_id' => $resource->id,
        'requested_by' => $requester->id,
    ]);

    $this->actingAs($requester)
        ->postJson(route('api.bookings.edit-requests.store', $booking), [
            'people' => array_map(fn ($i) => "Guest {$i}", range('A', 'K')),
            'note' => 'More people',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('people');

    expect($booking->editRequests()->count())->toBe(0);
});

// ---------------------------------------------------------------- ISS-004

test('ISS-004: working hours must close after they open', function () {
    $admin = auditUserWith('admin.access', 'working-hours.manage');

    $days = collect(range(0, 6))->map(fn (int $day) => [
        'day_of_week' => $day,
        'opens_at' => '17:00',
        'closes_at' => '09:00',
    ])->all();

    $this->actingAs($admin)
        ->putJson(route('admin.working-hours.update'), ['days' => $days])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('days.0.closes_at');
});

// ---------------------------------------------------------------- ISS-005

test('ISS-005: moving one occurrence requires both a start and an end', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=4')->create([
        'calendar_id' => $calendar->id,
        'created_by' => $user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    $this->actingAs($user)->patchJson(route('api.events.occurrence.update', $event), [
        'original_starts_at' => '2026-08-04T01:00:00Z',
        'scope' => 'this',
        'starts_at' => '2026-08-04T05:00:00Z',
    ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');

    expect($event->overrides()->count())->toBe(0);
});

// ---------------------------------------------------------------- ISS-006

test('ISS-006: deleting a division retires its calendar', function () {
    $admin = auditUserWith('admin.access', 'divisions.manage', 'calendars.manage');
    $division = Division::factory()->create();

    $calendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $division->id)
        ->firstOrFail();

    $this->actingAs($admin)
        ->delete(route('admin.divisions.destroy', $division))
        ->assertRedirect();

    expect(Calendar::query()->find($calendar->id))->toBeNull()
        ->and(Calendar::withTrashed()->find($calendar->id)->trashed())->toBeTrue();
});

test('ISS-006: deactivating a division hides its calendar immediately', function () {
    $division = Division::factory()->create();
    $member = User::factory()->create(['division_id' => $division->id]);

    $calendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $division->id)
        ->firstOrFail();

    $access = app(CalendarAccessService::class);
    expect($access->accessibleCalendarIds($member))->toContain($calendar->id);

    $admin = auditUserWith('admin.access', 'divisions.manage');

    $this->actingAs($admin)
        ->patch(route('admin.divisions.update', $division), [
            'name' => $division->name,
            'color' => $division->color,
            'is_active' => false,
        ])
        ->assertRedirect();

    expect($access->accessibleCalendarIds($member))->not->toContain($calendar->id);
});

// ---------------------------------------------------------------- ISS-007

test('ISS-007: suspended users cannot reach the settings pages', function () {
    $suspended = User::factory()->suspended()->create();

    $this->actingAs($suspended)
        ->get(route('profile.edit'))
        ->assertRedirect(route('approval.pending'));

    $this->actingAs($suspended)
        ->get(route('notifications.edit'))
        ->assertRedirect(route('approval.pending'));
});

// ---------------------------------------------------------------- ISS-008

test('ISS-008: private events do not expose their discussion to other users', function () {
    $creator = User::factory()->create();
    $other = User::factory()->create();
    $calendar = Calendar::factory()->organization()->create();
    $event = Event::factory()->private()->create([
        'calendar_id' => $calendar->id,
        'created_by' => $creator->id,
    ]);

    $this->actingAs($other)
        ->getJson(route('api.events.comments.index', $event))
        ->assertForbidden();

    $this->actingAs($other)
        ->postJson(route('api.events.comments.store', $event), ['body' => 'peek'])
        ->assertForbidden();

    $this->actingAs($creator)
        ->postJson(route('api.events.comments.store', $event), ['body' => 'mine'])
        ->assertCreated();
});

// ---------------------------------------------------------------- ISS-009

test('ISS-009: an invitation for an email that already has an account is rejected cleanly', function () {
    $invitation = Invitation::factory()->create(['email' => 'taken@fdcp.gov.ph']);
    User::factory()->create(['email' => 'taken@fdcp.gov.ph']);

    $this->get(route('invitation.show', $invitation->token))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->post(route('invitation.store', $invitation->token), [
        'name' => 'Dup',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'taken@fdcp.gov.ph')->count())->toBe(1);
});

// ---------------------------------------------------------------- ISS-010

test('ISS-010: a resource type with archived resources cannot be deleted', function () {
    $admin = auditUserWith('admin.access', 'resource-types.manage');
    $type = ResourceType::factory()->create();
    $resource = Resource::factory()->create(['resource_type_id' => $type->id]);
    $resource->delete();

    $this->actingAs($admin)
        ->delete(route('admin.resource-types.destroy', $type))
        ->assertForbidden();

    expect(ResourceType::query()->find($type->id))->not->toBeNull();
});

// ---------------------------------------------------------------- ISS-011

test('ISS-011: the register page is unavailable when self-registration is off', function () {
    app(RegistrationSettings::class)->fill(['self_registration_enabled' => false])->save();

    $this->get(route('register'))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------- ISS-012

test('ISS-012: applying a change request survives a deleted requester account', function () {
    $requester = User::factory()->create();
    $resource = Resource::factory()->create();
    $booking = Booking::factory()->approved()->create([
        'resource_id' => $resource->id,
        'requested_by' => $requester->id,
        'starts_at' => now()->addDays(3)->setTime(9, 0),
        'ends_at' => now()->addDays(3)->setTime(11, 0),
    ]);
    $change = $booking->editRequests()->create([
        'requested_by' => $requester->id,
        'proposed_starts_at' => now()->addDays(4)->setTime(9, 0),
        'proposed_ends_at' => now()->addDays(4)->setTime(11, 0),
    ]);

    $requester->delete();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->post(route('admin.edit-requests.apply', $change))
        ->assertRedirect();

    expect($change->refresh()->status->value)->toBe('applied');
});

// ---------------------------------------------------------------- ISS-013

test('ISS-013: an all-events time shift keeps per-occurrence edits', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=4')->create([
        'calendar_id' => $calendar->id,
        'created_by' => $user->id,
        'title' => 'Standup',
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    // Rename only the Aug 4 occurrence, then shift the whole series by a day and two hours.
    $this->actingAs($user)->patchJson(route('api.events.occurrence.update', $event), [
        'original_starts_at' => '2026-08-04T01:00:00Z',
        'scope' => 'this',
        'title' => 'Special standup',
    ])->assertOk();

    $this->actingAs($user)->patchJson(route('api.events.occurrence.update', $event), [
        'original_starts_at' => '2026-08-05T01:00:00Z',
        'scope' => 'all',
        'starts_at' => '2026-08-06T03:00:00Z',
        'ends_at' => '2026-08-06T04:00:00Z',
    ])->assertOk();

    $occurrences = collect($this->actingAs($user)->getJson(route('api.events.index', [
        'start' => '2026-08-01T00:00:00Z',
        'end' => '2026-09-01T00:00:00Z',
    ]))->json('data'));

    expect($occurrences)->toHaveCount(4)
        ->and($occurrences->firstWhere('title', 'Special standup')['starts_at'])->toBe('2026-08-05T03:00:00+00:00');
});

// ---------------------------------------------------------------- ISS-016

test('ISS-016: holiday blocking uses the office day, not the UTC day', function () {
    $requester = User::factory()->create();
    $type = ResourceType::factory()->create(['block_holidays' => true]);
    $resource = Resource::factory()->create(['resource_type_id' => $type->id]);

    $holiday = now('Asia/Manila')->addDays(5)->toDateString();
    Holiday::query()->create([
        'name' => 'Audit holiday',
        'date' => $holiday,
        'recurs_annually' => false,
        'type' => 'regular',
        'blocks_bookings' => true,
    ]);

    // 07:00 in Manila on the holiday is 23:00 UTC the evening before.
    $start = Carbon::parse("{$holiday} 07:00", 'Asia/Manila');

    $this->actingAs($requester)
        ->postJson(route('api.bookings.store'), [
            'resource_id' => $resource->id,
            'title' => 'Early meeting',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHour()->toIso8601String(),
            'people' => ['Ana Reyes'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');
});

// ---------------------------------------------------------------- ISS-018

test('ISS-018: a pending account calling the JSON API gets 403, not a redirect', function () {
    $pending = User::factory()->pending()->create();

    $this->actingAs($pending)
        ->getJson(route('api.calendars.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------- ISS-019

test('ISS-019: cancel and reject re-read the booking under lock before deciding', function () {
    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $booking = Booking::factory()->create(['requested_by' => $requester->id]);
    $service = app(BookingService::class);

    // Another request rejected it meanwhile; this stale instance still says pending.
    $stale = Booking::query()->findOrFail($booking->id);
    Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Rejected->value]);

    expect(fn () => $service->cancel($stale, $requester))->toThrow(ValidationException::class)
        ->and($booking->refresh()->status)->toBe(BookingStatus::Rejected);

    $other = Booking::factory()->create(['requested_by' => $requester->id]);
    $staleOther = Booking::query()->findOrFail($other->id);
    Booking::query()->whereKey($other->id)->update(['status' => BookingStatus::Cancelled->value]);

    expect(fn () => $service->reject($staleOther, $reviewer, 'No longer needed here'))->toThrow(ValidationException::class)
        ->and($other->refresh()->status)->toBe(BookingStatus::Cancelled);
});

// ---------------------------------------------------------------- ISS-024

test('ISS-024: unknown pages render the branded error page with the right status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/resources/nope')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404));

    // JSON callers keep JSON.
    $this->actingAs($user)
        ->getJson('/api/v1/resources/999/availability?date=2026-10-01')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

// ---------------------------------------------------------------- ISS-027

test('ISS-027: a weekly schedule needs one row per weekday', function () {
    $admin = auditUserWith('admin.access', 'working-hours.manage');

    $days = collect(range(0, 6))->map(fn () => [
        'day_of_week' => 1,
        'opens_at' => '08:00',
        'closes_at' => '17:00',
    ])->all();

    $this->actingAs($admin)
        ->putJson(route('admin.working-hours.update'), ['days' => $days])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('days.0.day_of_week');
});

// ---------------------------------------------------------------- ISS-028

test('ISS-028: an overnight booking that runs into a holiday is blocked, one ending at midnight is not', function () {
    $requester = User::factory()->create();
    $type = ResourceType::factory()->autoApproved()->create(['block_holidays' => true]);
    $resource = Resource::factory()->create(['resource_type_id' => $type->id]);

    $holiday = now('Asia/Manila')->addDays(6)->toDateString();
    Holiday::query()->create([
        'name' => 'Overnight holiday',
        'date' => $holiday,
        'recurs_annually' => false,
        'type' => 'regular',
        'blocks_bookings' => true,
    ]);

    $eve = Carbon::parse("{$holiday} 18:00", 'Asia/Manila')->subDay();

    $this->actingAs($requester)
        ->postJson(route('api.bookings.store'), [
            'resource_id' => $resource->id,
            'title' => 'Overnight trip',
            'starts_at' => $eve->toIso8601String(),
            'ends_at' => $eve->copy()->addHours(16)->toIso8601String(),
            'people' => ['Ana Reyes'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');

    $this->actingAs($requester)
        ->postJson(route('api.bookings.store'), [
            'resource_id' => $resource->id,
            'title' => 'Evening before',
            'starts_at' => $eve->toIso8601String(),
            'ends_at' => $eve->copy()->addHours(6)->toIso8601String(),
            'people' => ['Ana Reyes'],
        ])
        ->assertCreated();
});

// ---------------------------------------------------------------- ISS-029

test('ISS-029: unit members see announcements aimed at their parent division', function () {
    $parent = Division::factory()->create();
    $unit = Division::factory()->create(['parent_id' => $parent->id]);
    $unitMember = User::factory()->create(['division_id' => $unit->id]);
    $parentMember = User::factory()->create(['division_id' => $parent->id]);

    foreach ([[$parent->id, 'Parent notice'], [$unit->id, 'Unit notice']] as [$divisionId, $title]) {
        Announcement::query()->create([
            'title' => $title,
            'body' => 'Body',
            'level' => 'info',
            'starts_at' => now()->subHour(),
            'division_id' => $divisionId,
            'is_dismissible' => true,
            'is_active' => true,
        ]);
    }

    $this->actingAs($unitMember)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('announcements', 2));

    $this->actingAs($parentMember)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('announcements', 1)
            ->where('announcements.0.title', 'Parent notice'));
});

// ---------------------------------------------------------------- ISS-030

test('ISS-030: applying a change whose proposed time has passed explains what to do', function () {
    $requester = User::factory()->create();
    $resource = Resource::factory()->create();
    $booking = Booking::factory()->approved()->create([
        'resource_id' => $resource->id,
        'requested_by' => $requester->id,
        'starts_at' => now()->addDays(3)->setTime(9, 0),
        'ends_at' => now()->addDays(3)->setTime(11, 0),
    ]);
    $change = $booking->editRequests()->create([
        'requested_by' => $requester->id,
        'proposed_starts_at' => now()->subDay()->setTime(9, 0),
        'proposed_ends_at' => now()->subDay()->setTime(11, 0),
    ]);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->postJson(route('admin.edit-requests.apply', $change))
        ->assertUnprocessable()
        ->assertJsonPath('errors.starts_at.0', 'The proposed time has already passed. Reject this request and ask for a new one.');

    expect($change->refresh()->status->value)->toBe('pending');
});

// ---------------------------------------------------------------- ISS-032

test('ISS-032: a profile email change cannot leave the allowed domain', function () {
    app(RegistrationSettings::class)->fill(['allowed_email_domain' => 'fdcp.gov.ph'])->save();
    $user = User::factory()->create(['email' => 'someone@fdcp.gov.ph']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Someone', 'email' => 'someone@gmail.com'])
        ->assertSessionHasErrors('email');

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Someone Else', 'email' => 'someone@fdcp.gov.ph'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->name)->toBe('Someone Else');
});

test('ISS-032: SSO sign-in for an archived account fails cleanly instead of creating a twin', function () {
    $archived = User::factory()->create(['email' => 'gone@fdcp.gov.ph']);
    $archived->delete();

    Socialite::shouldReceive('driver->user')->andReturn((new SocialiteUser)->setRaw([
        'sub' => 'sso-123',
        'name' => 'Gone Person',
        'email' => 'gone@fdcp.gov.ph',
        'is_active' => true,
        'apps' => ['calendar' => ['role' => 'staff']],
    ]));

    $this->get(route('sso.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    expect(User::withTrashed()->where('email', 'gone@fdcp.gov.ph')->count())->toBe(1)
        ->and(auth()->check())->toBeFalse();
});

// ---------------------------------------------------------------- ISS-033

test('ISS-033: resource type names that share a URL slug are rejected', function () {
    $admin = auditUserWith('admin.access', 'resource-types.manage');
    $role = Role::query()->where('name', 'staff')->firstOrFail();

    $payload = fn (string $name) => [
        'name' => $name,
        'approver_role_id' => $role->id,
        'requires_approval' => true,
        'min_lead_time_minutes' => 0,
    ];

    $this->actingAs($admin)
        ->post(route('admin.resource-types.store'), $payload('Board Room'))
        ->assertRedirect();

    $this->actingAs($admin)
        ->postJson(route('admin.resource-types.store'), $payload('Board-Room'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

// ---------------------------------------------------------------- ISS-034

test('ISS-034: sub-daily recurrence rules are rejected', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();

    $payload = fn (string $rule) => [
        'calendar_id' => $calendar->id,
        'title' => 'Frequency check',
        'starts_at' => '2026-10-20T01:00:00Z',
        'ends_at' => '2026-10-20T02:00:00Z',
        'rrule' => $rule,
    ];

    foreach (['FREQ=MINUTELY', 'FREQ=SECONDLY;COUNT=5', 'FREQ=HOURLY'] as $rule) {
        $this->actingAs($user)->postJson(route('api.events.store'), $payload($rule))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rrule');
    }

    $this->actingAs($user)->postJson(route('api.events.store'), $payload('FREQ=DAILY;COUNT=3'))
        ->assertCreated();
});

// ---------------------------------------------------------------- ISS-035

test('ISS-035: role, user-role and calendar-share changes are written to the audit log', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $role = Role::create(['name' => 'audit_logged_role']);
    $target = User::factory()->create();

    $this->actingAs($superAdmin)
        ->patch(route('admin.roles.update', $role), [
            'name' => 'audit_logged_role',
            'description' => 'x',
            'permissions' => ['admin.access'],
        ])
        ->assertRedirect();

    $this->actingAs($superAdmin)
        ->patch(route('admin.users.update', $target), [
            'name' => $target->name,
            'division_id' => null,
            'roles' => ['audit_logged_role'],
        ])
        ->assertRedirect();

    $calendar = Calendar::factory()->ownedBy($superAdmin)->create();

    $this->actingAs($superAdmin)
        ->post(route('calendars.shares.store', $calendar), [
            'user_id' => $target->id,
            'permission' => 'viewer',
        ])
        ->assertRedirect();

    $log = Activity::query()->where('causer_id', $superAdmin->id);

    expect((clone $log)->where('event', 'permissions-updated')->where('subject_id', $role->id)->exists())->toBeTrue()
        ->and((clone $log)->where('event', 'updated')->where('subject_type', User::class)->where('subject_id', $target->id)->exists())->toBeTrue()
        ->and((clone $log)->where('event', 'shared')->where('subject_id', $calendar->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- ISS-039

test('ISS-039: the people list is bounded even without a room capacity', function () {
    $requester = User::factory()->create();
    $resource = Resource::factory()->create(['capacity' => null]);

    $start = now()->addDays(2)->setTime(9, 0);

    $this->actingAs($requester)
        ->postJson(route('api.bookings.store'), [
            'resource_id' => $resource->id,
            'title' => 'Crowd',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHour()->toIso8601String(),
            'people' => array_map(fn ($i) => "Guest {$i}", range(1, 101)),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('people');
});

// ---------------------------------------------------------------- ISS-040

test('ISS-040: one occurrence cannot take an archived event type', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->ownedBy($user)->create();
    $inactive = EventType::factory()->create(['is_active' => false]);
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=3')->create([
        'calendar_id' => $calendar->id,
        'created_by' => $user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    $this->actingAs($user)->patchJson(route('api.events.occurrence.update', $event), [
        'original_starts_at' => '2026-08-04T01:00:00Z',
        'scope' => 'this',
        'event_type_id' => $inactive->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('event_type_id');
});
