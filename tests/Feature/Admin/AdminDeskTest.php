<?php

use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $role = Role::query()->where('name', 'board_room_approver')->firstOrFail();

    $this->approver = User::factory()->create();
    $this->approver->assignRole($role);

    $type = ResourceType::factory()->create(['approver_role_id' => $role->id]);
    $this->room = Resource::factory()->create(['resource_type_id' => $type->id]);

    $this->day = Carbon::now('Asia/Manila')->addDays(2)->setTime(10, 0);
});

test('approvers get their queue with clashes, and only the panels they can act on', function () {
    $held = Booking::factory()->approved()->at($this->day->copy()->utc())
        ->create(['resource_id' => $this->room->id, 'purpose' => 'Screening committee']);
    $request = Booking::factory()->at($this->day->copy()->addHour()->utc())
        ->create(['resource_id' => $this->room->id]);

    $this->actingAs($this->approver)
        ->get(route('admin.dashboard', ['date' => $this->day->toDateString()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Dashboard')
            ->has('queue', 1)
            ->where('queue.0.id', $request->id)
            ->where('queue.0.clash.status', 'approved')
            ->where('schedule.date', $this->day->toDateString())
            ->where('schedule.types.0.resources.0.bookings.0.id', $held->id)
            ->where('schedule.types.0.resources.0.bookings.1.can_approve', true)
            ->has('demand', 14)
            ->where('people', null)
            ->where('notices', null)
            ->where('activity', null)
            ->where('health', null));
});

test('super admins also see sign-ups and the notes panels', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $signUp = User::factory()->pending()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queue.0.kind', 'registration')
            ->where('queue.0.id', $signUp->id)
            ->where('people.pending', 1)
            ->has('notices.live')
            ->has('activity')
            ->has('health.failed'));
});

test('the schedule date must be a real date', function () {
    $this->actingAs($this->approver)
        ->get(route('admin.dashboard', ['date' => 'next-tuesday']))
        ->assertSessionHasErrors('date');
});

test('the schedule shows purpose and headcount only for bookings this approver can review', function () {
    $carType = ResourceType::factory()->create([
        'approver_role_id' => Role::query()->where('name', 'vehicle_approver')->firstOrFail()->id,
    ]);
    $car = Resource::factory()->create(['resource_type_id' => $carType->id]);
    $at = $this->day->copy()->utc();

    $mine = Booking::factory()->approved()->at($at)
        ->create(['resource_id' => $this->room->id, 'purpose' => 'Screening committee', 'attendee_count' => 6]);
    $theirs = Booking::factory()->approved()->at($at)
        ->create(['resource_id' => $car->id, 'purpose' => 'Confidential site visit', 'attendee_count' => 4]);

    $response = $this->actingAs($this->approver)
        ->get(route('admin.dashboard', ['date' => $this->day->toDateString()]))
        ->assertOk();

    $bookings = collect($response->viewData('page')['props']['schedule']['types'])
        ->flatMap(fn (array $type) => collect($type['resources'])->flatMap(fn (array $resource) => $resource['bookings']))
        ->keyBy('id');

    expect($bookings[$mine->id])
        ->purpose->toBe('Screening committee')
        ->attendee_count->toBe(6)
        ->and($bookings[$theirs->id])
        ->purpose->toBeNull()
        ->attendee_count->toBeNull()
        ->title->not->toBe('Confidential site visit');
});
