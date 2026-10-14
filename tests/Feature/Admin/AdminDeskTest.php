<?php

use App\Enums\EditRequestStatus;
use App\Models\Booking;
use App\Models\BookingEditRequest;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $roomRole = Role::query()->where('name', 'board_room_approver')->firstOrFail();
    $carRole = Role::query()->where('name', 'vehicle_approver')->firstOrFail();

    $this->approver = User::factory()->create();
    $this->approver->assignRole($roomRole);

    $this->rooms = ResourceType::factory()->create(['name' => 'Boardroom', 'slug' => 'board-room', 'approver_role_id' => $roomRole->id]);
    $this->cars = ResourceType::factory()->create(['name' => 'Carpool', 'slug' => 'vehicle', 'approver_role_id' => $carRole->id]);
    $this->room = Resource::factory()->create(['resource_type_id' => $this->rooms->id]);
    $this->car = Resource::factory()->create(['resource_type_id' => $this->cars->id]);

    $this->day = Carbon::now('Asia/Manila')->addDays(2)->setTime(10, 0);
});

test('an approver gets only their own desk: its requests, rooms and week', function () {
    $held = Booking::factory()->approved()->at($this->day->copy()->utc())
        ->create(['resource_id' => $this->room->id, 'purpose' => 'Screening committee']);
    $request = Booking::factory()->at($this->day->copy()->addHour()->utc())
        ->create(['resource_id' => $this->room->id]);
    Booking::factory()->at($this->day->copy()->utc())->create(['resource_id' => $this->car->id]);

    $this->actingAs($this->approver)
        ->get(route('admin.dashboard', ['date' => $this->day->toDateString()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Dashboard')
            ->has('tabs', 1)
            ->where('tabs.0.slug', 'board-room')
            ->where('tabs.0.waiting', 1)
            ->where('type.slug', 'board-room')
            ->has('requests', 1)
            ->where('requests.0.id', $request->id)
            ->where('requests.0.clash.status', 'approved')
            ->where('date', $this->day->toDateString())
            ->has('resources', 1)
            ->where('resources.0.id', $this->room->id)
            ->where('resources.0.bookings.0.id', $held->id)
            ->where('resources.0.bookings.1.can_approve', true)
            ->has('week', 7)
            ->where('signups', null));
});

test('the week strip counts each day and what still waits', function () {
    Booking::factory()->approved()->at($this->day->copy()->utc())->create(['resource_id' => $this->room->id]);
    Booking::factory()->at($this->day->copy()->addHours(2)->utc())->create(['resource_id' => $this->room->id]);

    $response = $this->actingAs($this->approver)
        ->get(route('admin.dashboard', ['date' => $this->day->toDateString()]))
        ->assertOk();

    $day = collect($response->viewData('page')['props']['week'])->firstWhere('date', $this->day->toDateString());

    expect($day)->toBe(['date' => $this->day->toDateString(), 'bookings' => 2, 'waiting' => 1]);
});

test('an approver cannot open the other desk', function () {
    $this->actingAs($this->approver)
        ->get(route('admin.dashboard', ['type' => 'vehicle']))
        ->assertForbidden();

    $this->actingAs($this->approver)
        ->get(route('admin.dashboard', ['type' => 'no-such-type']))
        ->assertForbidden();
});

test('super admins switch between both desks', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $trip = Booking::factory()->at($this->day->copy()->utc())->create(['resource_id' => $this->car->id]);
    User::factory()->pending()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tabs', 2)
            ->where('type.slug', 'board-room')
            ->where('requests', [])
            ->where('signups', 1));

    $this->actingAs($admin)
        ->get(route('admin.dashboard', ['type' => 'vehicle']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('type.slug', 'vehicle')
            ->where('requests.0.id', $trip->id)
            ->where('resources.0.id', $this->car->id));
});

test('change requests land on their own desk as a before and after', function () {
    $booking = Booking::factory()->approved()->at($this->day->copy()->utc())
        ->create(['resource_id' => $this->room->id, 'attendee_count' => 4]);
    $change = BookingEditRequest::create([
        'booking_id' => $booking->id,
        'requested_by' => $booking->requested_by,
        'status' => EditRequestStatus::Pending,
        'proposed_starts_at' => $booking->starts_at->copy()->addHours(3),
        'proposed_ends_at' => $booking->ends_at->copy()->addHours(3),
        'proposed_attendee_count' => 6,
        'note' => 'Our guest lands later.',
    ]);

    $this->actingAs($this->approver)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.kind', 'change')
            ->where('requests.0.id', $change->id)
            ->where('requests.0.current.starts_at', $booking->starts_at->toIso8601String())
            ->where('requests.0.starts_at', $change->proposed_starts_at->toIso8601String())
            ->where('requests.0.current.attendee_count', 4)
            ->where('requests.0.proposed_attendee_count', 6));
});

test('admins who review no bookings get an empty desk with sign-ups', function () {
    $divisionAdmin = User::factory()->create();
    $divisionAdmin->assignRole('division_admin');
    User::factory()->pending()->create();

    $this->actingAs($divisionAdmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tabs', [])
            ->where('type', null)
            ->where('requests', [])
            ->where('resources', [])
            ->where('signups', 1));
});

test('the schedule date must be a real date', function () {
    $this->actingAs($this->approver)
        ->get(route('admin.dashboard', ['date' => 'next-tuesday']))
        ->assertSessionHasErrors('date');
});
