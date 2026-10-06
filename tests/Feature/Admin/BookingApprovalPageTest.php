<?php

use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $role = Role::query()->where('name', 'board_room_approver')->firstOrFail();

    $this->approver = User::factory()->create();
    $this->approver->assignRole($role);

    $this->rooms = ResourceType::factory()->create(['approver_role_id' => $role->id]);
    $this->room = Resource::factory()->create(['resource_type_id' => $this->rooms->id]);

    // Another approver's type: its requests stay out of this queue.
    $this->vehicles = ResourceType::factory()->create([
        'approver_role_id' => Role::query()->where('name', 'vehicle_approver')->value('id'),
    ]);
    $this->van = Resource::factory()->create(['resource_type_id' => $this->vehicles->id]);
});

test('the queue lists only requests this reviewer may decide, with their clash', function () {
    $start = Carbon::parse('+3 days 09:00');

    $held = Booking::factory()->approved()->at($start)->create(['resource_id' => $this->room->id]);
    $mine = Booking::factory()->at($start->copy()->addHour())->create(['resource_id' => $this->room->id]);
    Booking::factory()->at($start)->create(['resource_id' => $this->van->id]);

    $this->actingAs($this->approver)
        ->get(route('admin.bookings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/bookings/Index')
            ->has('requests', 1)
            ->where('requests.0.id', $mine->id)
            ->where('requests.0.clash.status', 'approved')
            ->where('requests.0.clash.starts_at', $held->starts_at->toIso8601String())
            ->has('schedule.types'));
});

test('the schedule follows the date parameter', function () {
    $this->actingAs($this->approver)
        ->get(route('admin.bookings.index', ['date' => '2026-12-01']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('schedule.date', '2026-12-01'));
});

test('a malformed date is rejected', function () {
    $this->actingAs($this->approver)
        ->get(route('admin.bookings.index', ['date' => 'tomorrow']))
        ->assertSessionHasErrors('date');
});

test('people without the approve permission cannot open the page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.bookings.index'))
        ->assertForbidden();
});
