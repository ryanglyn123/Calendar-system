<?php

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
use App\Models\User;

beforeEach(function () {
    $this->division = Division::factory()->create();
    $this->member = User::factory()->create(['division_id' => $this->division->id]);
});

function unitUnder(Division $parent, string $name = 'Field Unit'): Division
{
    return Division::factory()->create(['parent_id' => $parent->id, 'name' => $name]);
}

test('a member can create a unit under their own division', function () {
    $this->actingAs($this->member)
        ->post(route('division-units.store'), [
            'name' => 'Cinema Evaluation Unit',
            'color' => '#86C6F5',
        ])
        ->assertRedirect();

    $unit = Division::query()->where('name', 'Cinema Evaluation Unit')->firstOrFail();

    expect($unit->parent_id)->toBe($this->division->id);

    // The observer created the unit's own calendar.
    $calendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $unit->id)
        ->firstOrFail();

    // Parent-division members see the unit calendar on the division page.
    $props = $this->actingAs($this->member)->get(route('calendar.division'))->inertiaProps();
    expect(collect($props['initialCalendars'])->pluck('id'))->toContain($calendar->id);
});

test('unit members see the parent calendar but write only their own', function () {
    $unit = unitUnder($this->division);
    $unitMember = User::factory()->create(['division_id' => $unit->id]);

    $parentCalendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $this->division->id)
        ->firstOrFail();
    $unitCalendar = Calendar::query()
        ->where('type', CalendarType::Division->value)
        ->where('division_id', $unit->id)
        ->firstOrFail();

    $props = $this->actingAs($unitMember)->get(route('calendar.division'))->inertiaProps();
    expect(collect($props['initialCalendars'])->pluck('id'))
        ->toContain($parentCalendar->id)
        ->toContain($unitCalendar->id);

    $payload = fn (int $calendarId) => [
        'calendar_id' => $calendarId,
        'title' => 'Sync',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
    ];

    $this->actingAs($unitMember)
        ->postJson(route('api.events.store'), $payload($unitCalendar->id))
        ->assertCreated();

    $this->actingAs($unitMember)
        ->postJson(route('api.events.store'), $payload($parentCalendar->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('calendar_id');
});

test('a unit has its own chat and also sees the parent chat', function () {
    $unit = unitUnder($this->division);
    $unitMember = User::factory()->create(['division_id' => $unit->id]);

    $parentRoom = $this->division->conversation;
    $unitRoom = $unit->conversation;

    // The unit member's conversation list has BOTH rooms.
    $conversations = collect($this->actingAs($unitMember)
        ->get(route('dashboard'))
        ->inertiaProps()['chatConversations'])
        ->pluck('id');

    expect($conversations)->toContain($parentRoom->id)->toContain($unitRoom->id);

    // A parent member sees the parent room but NOT the unit's private room.
    $parentList = collect($this->actingAs($this->member)
        ->get(route('dashboard'))
        ->inertiaProps()['chatConversations'])
        ->pluck('id');

    expect($parentList)->toContain($parentRoom->id)->not->toContain($unitRoom->id);

    // Unit chat is private to unit members.
    $this->actingAs($unitMember)
        ->postJson(route('api.chat.messages.store', $unitRoom), ['body' => 'unit only'])
        ->assertCreated();

    $this->actingAs($this->member)
        ->getJson(route('api.chat.messages.index', $unitRoom))
        ->assertForbidden();

    // Parent chat is shared: the unit member can post, parent members see it.
    $this->actingAs($unitMember)
        ->postJson(route('api.chat.messages.store', $parentRoom), ['body' => 'to the whole division'])
        ->assertCreated();

    $this->actingAs($this->member)
        ->getJson(route('api.chat.messages.index', $parentRoom))
        ->assertOk()
        ->assertJsonPath('messages.0.body', 'to the whole division');
});

test('a member can rename and delete a unit of their division', function () {
    $unit = unitUnder($this->division, 'Old Unit');

    $this->actingAs($this->member)
        ->patch(route('division-units.update', $unit), ['name' => 'New Unit', 'color' => '#F5A6D8'])
        ->assertRedirect();

    expect($unit->refresh()->name)->toBe('New Unit')
        ->and(Calendar::query()->where('division_id', $unit->id)->where('is_default', true)->value('name'))
        ->toBe('New Unit');

    $this->actingAs($this->member)
        ->delete(route('division-units.destroy', $unit))
        ->assertRedirect();

    expect(Division::query()->find($unit->id))->toBeNull()
        ->and(Calendar::query()->where('division_id', $unit->id)->exists())->toBeFalse();
});

test('units with members cannot be deleted', function () {
    $unit = unitUnder($this->division);
    User::factory()->create(['division_id' => $unit->id]);

    $this->actingAs($this->member)
        ->delete(route('division-units.destroy', $unit))
        ->assertForbidden();
});

test('units cannot contain units and root divisions are protected', function () {
    $unit = unitUnder($this->division);
    $unitMember = User::factory()->create(['division_id' => $unit->id]);

    // A unit member cannot create a sub-unit.
    $this->actingAs($unitMember)
        ->post(route('division-units.store'), ['name' => 'Sub-unit', 'color' => '#86C6F5'])
        ->assertForbidden();

    // Root divisions cannot be touched through unit routes.
    $this->actingAs($this->member)
        ->patch(route('division-units.update', $this->division), ['name' => 'Hijack', 'color' => '#000000'])
        ->assertForbidden();
});

test('members of other divisions cannot manage the unit, admins can', function () {
    $unit = unitUnder($this->division);
    $outsider = User::factory()->create(['division_id' => Division::factory()->create()->id]);

    $this->actingAs($outsider)
        ->patch(route('division-units.update', $unit), ['name' => 'Theirs', 'color' => '#000000'])
        ->assertForbidden();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->patch(route('division-units.update', $unit), ['name' => 'Renamed by admin', 'color' => '#86DBAE'])
        ->assertRedirect();

    expect($unit->refresh()->name)->toBe('Renamed by admin');
});

test('admins can assign a parent from the divisions setting', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->post(route('admin.divisions.store'), [
            'name' => 'Admin-made Unit',
            'color' => '#86C6F5',
            'parent_id' => $this->division->id,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(Division::query()->where('name', 'Admin-made Unit')->value('parent_id'))
        ->toBe($this->division->id);

    // A unit cannot be chosen as a parent (one level deep).
    $unit = Division::query()->where('name', 'Admin-made Unit')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('admin.divisions.store'), [
            'name' => 'Too Deep',
            'color' => '#86C6F5',
            'parent_id' => $unit->id,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('parent_id');
});
