<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->admin->assignRole('super_admin');

    // Start from an empty log: factories may record activity of their own.
    Activity::query()->delete();
});

function auditEntry(string $description, ?User $causer = null, ?string $event = null, string $at = 'now'): Activity
{
    return Activity::query()->forceCreate([
        'log_name' => 'default',
        'description' => $description,
        'event' => $event,
        'causer_type' => $causer?->getMorphClass(),
        'causer_id' => $causer?->id,
        'created_at' => Carbon::parse($at),
    ]);
}

test('the audit log filters by actor and lists the actors', function () {
    $other = User::factory()->create(['name' => 'Ben Other']);

    auditEntry('by admin', $this->admin);
    auditEntry('by other', $other);
    auditEntry('by system');

    $this->actingAs($this->admin)
        ->get(route('admin.audit.index', ['causer' => $other->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.causer', (string) $other->id)
            ->where('actors', fn ($actors) => collect($actors)->pluck('name')->all() === ['Ada Admin', 'Ben Other'])
            ->where('activities.data', fn ($rows) => collect($rows)->pluck('description')->all() === ['by other']));
});

test('the audit log filters by event and lists the events', function () {
    auditEntry('made', $this->admin, 'created');
    auditEntry('changed', $this->admin, 'updated');
    auditEntry('no event', $this->admin);

    $this->actingAs($this->admin)
        ->get(route('admin.audit.index', ['event' => 'updated']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events', ['created', 'updated'])
            ->where('activities.data', fn ($rows) => collect($rows)->pluck('description')->all() === ['changed']));
});

test('the audit log date range is inclusive office days in Manila', function () {
    // 2026-09-30 00:30 Manila is still 2026-09-29 in UTC.
    auditEntry('before', $this->admin, at: '2026-09-29 15:59:00 UTC');
    auditEntry('first minute', $this->admin, at: '2026-09-29 16:30:00 UTC');
    auditEntry('last minute', $this->admin, at: '2026-10-01 15:59:00 UTC');
    auditEntry('after', $this->admin, at: '2026-10-01 16:00:00 UTC');

    $this->actingAs($this->admin)
        ->get(route('admin.audit.index', ['from' => '2026-09-30', 'to' => '2026-10-01']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.from', '2026-09-30')
            ->where('filters.to', '2026-10-01')
            ->where('activities.data', fn ($rows) => collect($rows)->pluck('description')->sort()->values()->all() === ['first minute', 'last minute']));

    $this->actingAs($this->admin)
        ->get(route('admin.audit.index', ['to' => '2026-09-29']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activities.data', fn ($rows) => collect($rows)->pluck('description')->all() === ['before']));
});

test('the audit log rejects malformed filters', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.audit.index', ['from' => '2026-10-02', 'to' => '2026-10-01', 'causer' => 'abc']))
        ->assertSessionHasErrors(['to', 'causer']);

    $this->actingAs($this->admin)
        ->get(route('admin.audit.index', ['from' => '10/01/2026']))
        ->assertSessionHasErrors(['from']);
});
