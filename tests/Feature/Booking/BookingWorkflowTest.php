<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BookingDecidedNotification;
use App\Notifications\BookingRequestedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->requester = User::factory()->create();

    $this->approverRole = Role::query()->where('name', 'board_room_approver')->firstOrFail();

    $this->approver = User::factory()->create();
    $this->approver->assignRole($this->approverRole);

    $this->type = ResourceType::factory()->create([
        'approver_role_id' => $this->approverRole->id,
    ]);

    $this->resource = Resource::factory()->create(['resource_type_id' => $this->type->id]);
});

function bookingPayload(Resource $resource, string $start = '+2 days 09:00', int $hours = 2): array
{
    $startAt = Carbon::parse($start);

    return [
        'resource_id' => $resource->id,
        'title' => 'Team planning',
        'starts_at' => $startAt->toIso8601String(),
        'ends_at' => $startAt->copy()->addHours($hours)->toIso8601String(),
        'purpose' => 'Quarterly planning session',
        'attendee_count' => 5,
    ];
}

test('a booking request creates the event and notifies the approvers', function () {
    Notification::fake();

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.store'), bookingPayload($this->resource))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $booking = Booking::query()->firstOrFail();

    expect($booking->event->calendar_id)->toBe($this->resource->calendar_id);

    Notification::assertSentTo($this->approver, BookingRequestedNotification::class);
});

test('auto-approved types skip the pending state', function () {
    $this->type->update(['requires_approval' => false]);

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.store'), bookingPayload($this->resource))
        ->assertCreated()
        ->assertJsonPath('data.status', 'approved');
});

test('overlapping bookings are rejected', function () {
    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.store'), bookingPayload($this->resource))
        ->assertCreated();

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.store'), bookingPayload($this->resource, '+2 days 10:00'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');
});

test('approvers can approve and the requester hears about it', function () {
    Notification::fake();

    $booking = Booking::factory()->create([
        'resource_id' => $this->resource->id,
        'requested_by' => $this->requester->id,
    ]);

    $this->actingAs($this->approver)
        ->post(route('admin.bookings.approve', $booking))
        ->assertRedirect();

    expect($booking->refresh()->status)->toBe(BookingStatus::Approved);

    Notification::assertSentTo($this->requester, BookingDecidedNotification::class);
});

test('rejection requires a reason of at least ten characters', function () {
    $booking = Booking::factory()->create([
        'resource_id' => $this->resource->id,
        'requested_by' => $this->requester->id,
    ]);

    $this->actingAs($this->approver)
        ->post(route('admin.bookings.reject', $booking), ['reason' => 'short'])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->approver)
        ->post(route('admin.bookings.reject', $booking), [
            'reason' => 'The room is reserved for the board meeting.',
        ])
        ->assertRedirect();

    expect($booking->refresh()->status)->toBe(BookingStatus::Rejected);
});

test('users without the approver role cannot approve', function () {
    $random = User::factory()->create();
    $random->assignRole('staff');

    $booking = Booking::factory()->create(['resource_id' => $this->resource->id]);

    $this->actingAs($random)
        ->post(route('admin.bookings.approve', $booking))
        ->assertForbidden();
});

test('an approver of one type cannot approve another type', function () {
    $otherRole = Role::query()->where('name', 'vehicle_approver')->firstOrFail();
    $otherType = ResourceType::factory()->create(['approver_role_id' => $otherRole->id]);
    $otherResource = Resource::factory()->create(['resource_type_id' => $otherType->id]);

    $booking = Booking::factory()->create(['resource_id' => $otherResource->id]);

    $this->actingAs($this->approver)
        ->post(route('admin.bookings.approve', $booking))
        ->assertForbidden();
});

test('requesters can cancel their pending booking', function () {
    $booking = Booking::factory()->create([
        'resource_id' => $this->resource->id,
        'requested_by' => $this->requester->id,
    ]);

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.cancel', $booking))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('rejected bookings cannot be approved afterwards', function () {
    $booking = Booking::factory()->create([
        'resource_id' => $this->resource->id,
        'status' => BookingStatus::Rejected,
    ]);

    $this->actingAs($this->approver)
        ->post(route('admin.bookings.approve', $booking))
        ->assertSessionHasErrors('status');
});

test('booking rules are enforced', function () {
    $this->type->update([
        'min_lead_time_minutes' => 1440,
        'max_duration_minutes' => 60,
    ]);

    // Too little lead time.
    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.store'), bookingPayload($this->resource, '+2 hours'))
        ->assertUnprocessable();

    // Too long.
    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.store'), bookingPayload($this->resource, '+3 days 09:00', 3))
        ->assertUnprocessable();

    // Within the rules.
    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.store'), bookingPayload($this->resource, '+3 days 10:00', 1))
        ->assertCreated();
});

test('capacity limits attendee count', function () {
    $this->resource->update(['capacity' => 4]);

    $payload = bookingPayload($this->resource);
    $payload['attendee_count'] = 10;

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attendee_count');
});

test('booking events adopt the booking status color on the grid', function () {
    $payload = bookingPayload($this->resource);

    $this->actingAs($this->requester)->postJson(route('api.bookings.store'), $payload)->assertCreated();

    $start = Carbon::parse($payload['starts_at'])->subDay()->toIso8601String();
    $end = Carbon::parse($payload['ends_at'])->addDay()->toIso8601String();

    $occurrence = collect($this->actingAs($this->requester)
        ->getJson(route('api.events.index', ['start' => $start, 'end' => $end]))
        ->json('data'))
        ->firstWhere('booking.status', 'pending');

    expect($occurrence)->not->toBeNull()
        ->and($occurrence['color'])->toBe(BookingStatus::Pending->color())
        ->and($occurrence['can_edit'])->toBeFalse();
});
