<?php

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BookingDecidedNotification;
use App\Services\Booking\BookingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Request -> Pending -> Review -> Approved/Rejected -> Cancelled, from the
 * requester's side and the reviewer's, including the ways it must refuse.
 */
beforeEach(function () {
    Notification::fake();

    $roomRole = Role::query()->where('name', 'board_room_approver')->firstOrFail();
    $vehicleRole = Role::query()->where('name', 'vehicle_approver')->firstOrFail();

    $this->requester = User::factory()->create();
    $this->requester->assignRole('staff');
    $this->colleague = User::factory()->create();
    $this->colleague->assignRole('staff');

    $this->approver = User::factory()->create();
    $this->approver->assignRole($roomRole);
    $this->vehicleApprover = User::factory()->create();
    $this->vehicleApprover->assignRole($vehicleRole);

    $this->type = ResourceType::factory()->create(['approver_role_id' => $roomRole->id]);
    $this->room = Resource::factory()->create(['resource_type_id' => $this->type->id]);

    $this->start = Carbon::parse('+3 days 09:00');
});

function slot(Resource $resource, Carbon $start, int $hours = 2, string $title = 'Budget review'): array
{
    return [
        'resource_id' => $resource->id,
        'title' => $title,
        'starts_at' => $start->toIso8601String(),
        'ends_at' => $start->copy()->addHours($hours)->toIso8601String(),
        'purpose' => 'Quarterly numbers with Finance',
        'people' => ['Ana Reyes', 'Ben Cruz', 'Carla Lim', 'Dan Tan', 'Ela Go', 'Fe Uy'],
    ];
}

/** A booking made outside the API, with its event on the room's calendar at the same times. */
function roomBooking(Resource $resource, array $attributes = [], ?Carbon $start = null): Booking
{
    $factory = Booking::factory();

    if ($start !== null) {
        $factory = $factory->at($start->copy());
    }

    $booking = $factory->create(['resource_id' => $resource->id, ...$attributes]);

    $booking->event->update([
        'calendar_id' => $resource->calendar_id,
        'starts_at' => $booking->starts_at,
        'ends_at' => $booking->ends_at,
        'description' => $booking->purpose,
    ]);

    return $booking->refresh();
}

function requestSlot(User $user, Resource $resource, Carbon $start, int $hours = 2)
{
    return test()->actingAs($user)->postJson(route('api.bookings.store'), slot($resource, $start, $hours));
}

/** The requester's view of one booking on a calendar window around it. */
function bookingOnWindow(User $user, Booking $booking, array $query = []): ?array
{
    $response = test()->actingAs($user)->getJson(route('api.events.index', [
        'start' => $booking->starts_at->copy()->subDay()->toIso8601String(),
        'end' => $booking->ends_at->copy()->addDay()->toIso8601String(),
        ...$query,
    ]))->assertOk();

    return collect($response->json('data'))->firstWhere('booking.id', $booking->id);
}

test('a request lands as pending on the requester\'s calendars right away', function () {
    $id = requestSlot($this->requester, $this->room, $this->start)->assertCreated()->json('data.id');
    $booking = Booking::findOrFail($id);

    expect($booking->status)->toBe(BookingStatus::Pending);

    $onSection = bookingOnWindow($this->requester, $booking, ['resource_type_id' => $this->type->id]);
    $onMyCalendar = bookingOnWindow($this->requester, $booking, ['types' => ['organization', 'division', 'personal']]);

    expect($onSection['booking'])->toMatchArray(['status' => 'pending', 'is_mine' => true, 'can_review' => false])
        ->and($onSection['booking']['purpose'])->toBe('Quarterly numbers with Finance')
        ->and($onMyCalendar)->not->toBeNull()
        ->and($onMyCalendar['booking']['status'])->toBe('pending');
});

test('a double-submitted request returns the first booking instead of a clash', function () {
    $first = requestSlot($this->requester, $this->room, $this->start)->assertCreated()->json('data.id');
    $second = requestSlot($this->requester, $this->room, $this->start)->assertOk()->json('data.id');

    expect($second)->toBe($first)
        ->and(Booking::query()->where('resource_id', $this->room->id)->count())->toBe(1);
});

test('someone else cannot request a slot that is already held', function () {
    requestSlot($this->requester, $this->room, $this->start)->assertCreated();

    requestSlot($this->colleague, $this->room, $this->start->copy()->addHour())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');
});

test('a reviewer approves from the calendar and the requester sees it approved', function () {
    $booking = Booking::findOrFail(requestSlot($this->requester, $this->room, $this->start)->json('data.id'));
    $seen = bookingOnWindow($this->approver, $booking, ['resource_type_id' => $this->type->id]);

    expect($seen['booking']['can_review'])->toBeTrue();

    $this->actingAs($this->approver)
        ->postJson(route('api.bookings.approve', $booking), ['version' => $seen['booking']['version']])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect(bookingOnWindow($this->requester, $booking)['booking']['status'])->toBe('approved');

    Notification::assertSentTo($this->requester, BookingDecidedNotification::class);
});

test('a decision made on a stale view is refused', function () {
    $booking = Booking::findOrFail(requestSlot($this->requester, $this->room, $this->start)->json('data.id'));
    $version = BookingService::versionOf($booking);

    $this->travel(5)->seconds();
    $booking->update(['purpose' => 'Changed meanwhile']);

    $this->actingAs($this->approver)
        ->postJson(route('api.bookings.approve', $booking), ['version' => $version])
        ->assertStatus(409);

    expect($booking->refresh()->status)->toBe(BookingStatus::Pending);
});

test('invalid transitions are refused', function () {
    $rejected = roomBooking($this->room, ['status' => BookingStatus::Rejected]);
    $approved = roomBooking($this->room, ['status' => BookingStatus::Approved], $this->start->copy()->addDay());

    $this->actingAs($this->approver)->postJson(route('api.bookings.approve', $rejected))
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->actingAs($this->approver)->postJson(route('api.bookings.reject', $approved), ['reason' => 'Changed my mind about it'])
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->actingAs($this->approver)->postJson(route('api.bookings.approve', $approved))
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    // Closed bookings are not cancellable at all.
    $this->actingAs($this->approver)->postJson(route('api.bookings.cancel', $rejected), ['reason' => 'Cleaning up old rows'])
        ->assertForbidden();
});

test('staff cannot decide or cancel other people\'s bookings, by any route', function () {
    $booking = roomBooking($this->room, ['requested_by' => $this->requester->id]);

    $this->actingAs($this->colleague)->postJson(route('api.bookings.approve', $booking))->assertForbidden();
    $this->actingAs($this->colleague)->postJson(route('api.bookings.reject', $booking), ['reason' => 'Not your room at all'])->assertForbidden();
    $this->actingAs($this->colleague)->postJson(route('api.bookings.cancel', $booking), ['reason' => 'I want this slot too'])->assertForbidden();
    $this->actingAs($this->colleague)->post(route('admin.bookings.approve', $booking))->assertForbidden();
    $this->actingAs($this->requester)->postJson(route('api.bookings.approve', $booking))->assertForbidden();

    // Reviewing vehicles gives no say over rooms.
    $this->actingAs($this->vehicleApprover)->postJson(route('api.bookings.approve', $booking))->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->postJson(route('api.bookings.approve', $booking))->assertUnauthorized();

    expect($booking->refresh()->status)->toBe(BookingStatus::Pending);
});

test('a reviewer cancelling an approved booking must say why, and the slot frees up', function () {
    $booking = roomBooking($this->room, ['status' => BookingStatus::Approved, 'requested_by' => $this->requester->id], $this->start);

    $this->actingAs($this->approver)->postJson(route('api.bookings.cancel', $booking))
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->actingAs($this->approver)
        ->postJson(route('api.bookings.cancel', $booking), ['reason' => 'The room is needed for the board visit.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancelled_by_reviewer', true);

    expect($booking->refresh()->event->status)->toBe(EventStatus::Cancelled)
        ->and(Activity::query()->where('subject_id', $booking->id)->where('event', 'cancelled')->exists())->toBeTrue();

    Notification::assertSentTo($this->requester, BookingDecidedNotification::class);

    $seen = bookingOnWindow($this->requester, $booking);
    expect($seen['booking']['status'])->toBe('cancelled')
        ->and($seen['booking']['cancellation_reason'])->toBe('The room is needed for the board visit.');

    requestSlot($this->colleague, $this->room, $this->start)->assertCreated();
});

test('requesters withdraw their own bookings without a reason', function () {
    $booking = Booking::findOrFail(requestSlot($this->requester, $this->room, $this->start)->json('data.id'));

    $this->actingAs($this->requester)->postJson(route('api.bookings.cancel', $booking))
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    Notification::assertNotSentTo($this->requester, BookingDecidedNotification::class);
});

test('a request that clashes with an approved booking cannot be approved until the clash is resolved', function () {
    $held = roomBooking($this->room, ['status' => BookingStatus::Approved], $this->start);
    $request = roomBooking($this->room, [], $this->start->copy()->addHour());

    $this->actingAs($this->approver)->postJson(route('api.bookings.approve', $request))
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect($held->refresh()->status)->toBe(BookingStatus::Approved);

    $this->actingAs($this->approver)
        ->postJson(route('api.bookings.cancel', $held), ['reason' => 'Moving the review to accommodate the visit.'])
        ->assertOk();

    $this->actingAs($this->approver)->postJson(route('api.bookings.approve', $request))->assertOk();
});

test('other staff see who holds a slot but not the booking\'s details or closed bookings', function () {
    $approved = roomBooking($this->room, [
        'status' => BookingStatus::Approved,
        'requested_by' => $this->requester->id,
        'purpose' => 'Private negotiation',
    ], $this->start);

    $rejected = roomBooking($this->room, [
        'status' => BookingStatus::Rejected,
        'requested_by' => $this->requester->id,
        'rejection_reason' => 'Room closed for repainting',
    ], $this->start->copy()->addHours(3));

    $asColleague = bookingOnWindow($this->colleague, $approved->refresh());

    expect($asColleague['booking'])->toMatchArray([
        'is_mine' => false,
        'can_review' => false,
        'can_cancel' => false,
        'purpose' => null,
        'version' => null,
    ])
        ->and($asColleague['description'])->toBeNull()
        ->and(bookingOnWindow($this->colleague, $rejected->refresh()))->toBeNull();

    expect(bookingOnWindow($this->requester, $rejected)['booking']['rejection_reason'])->toBe('Room closed for repainting')
        ->and(bookingOnWindow($this->approver, $rejected)['booking']['rejection_reason'])->toBe('Room closed for repainting');
});

test('a double-submit still gets its booking back when the per-person cap is reached', function () {
    $this->type->update(['max_active_bookings_per_user' => 1]);

    $first = requestSlot($this->requester, $this->room, $this->start)->assertCreated()->json('data.id');

    expect(requestSlot($this->requester, $this->room, $this->start)->assertOk()->json('data.id'))->toBe($first);

    // A different slot is still held to the cap.
    requestSlot($this->requester, $this->room, $this->start->copy()->addDay())
        ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
});
