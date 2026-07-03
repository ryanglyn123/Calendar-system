<?php

use App\Enums\BookingStatus;
use App\Enums\EditRequestStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->requester = User::factory()->create();

    $role = Role::query()->where('name', 'board_room_approver')->firstOrFail();
    $this->approver = User::factory()->create();
    $this->approver->assignRole($role);

    $type = ResourceType::factory()->create(['approver_role_id' => $role->id]);
    $this->resource = Resource::factory()->create(['resource_type_id' => $type->id]);

    $this->booking = Booking::factory()->approved()->create([
        'resource_id' => $this->resource->id,
        'requested_by' => $this->requester->id,
        'starts_at' => Carbon::parse('+5 days 09:00'),
        'ends_at' => Carbon::parse('+5 days 11:00'),
    ]);
});

test('owners of an approved booking can propose a change', function () {
    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.edit-requests.store', $this->booking), [
            'starts_at' => Carbon::parse('+6 days 13:00')->toIso8601String(),
            'ends_at' => Carbon::parse('+6 days 15:00')->toIso8601String(),
            'note' => 'Speaker asked to move it.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.has_pending_edit', true);
});

test('only one pending change per booking', function () {
    $this->booking->editRequests()->create([
        'requested_by' => $this->requester->id,
        'proposed_purpose' => 'First proposal',
    ]);

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.edit-requests.store', $this->booking), [
            'purpose' => 'Second proposal',
        ])
        ->assertUnprocessable();
});

test('pending bookings cannot take edit requests', function () {
    $pending = Booking::factory()->create([
        'resource_id' => $this->resource->id,
        'requested_by' => $this->requester->id,
    ]);

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.edit-requests.store', $pending), [
            'purpose' => 'Nope',
        ])
        ->assertForbidden();
});

test('applying a change moves the booking and its event together', function () {
    $newStart = Carbon::parse('+6 days 13:00');

    $editRequest = $this->booking->editRequests()->create([
        'requested_by' => $this->requester->id,
        'proposed_starts_at' => $newStart,
        'proposed_ends_at' => $newStart->copy()->addHours(2),
    ]);

    $this->actingAs($this->approver)
        ->post(route('admin.edit-requests.apply', $editRequest))
        ->assertRedirect();

    $this->booking->refresh();

    expect($this->booking->starts_at->toIso8601String())->toBe($newStart->utc()->toIso8601String())
        ->and($this->booking->event->starts_at->toIso8601String())->toBe($newStart->utc()->toIso8601String())
        ->and($editRequest->refresh()->status)->toBe(EditRequestStatus::Applied)
        ->and($this->booking->status)->toBe(BookingStatus::Approved);
});

test('a change into an occupied slot is refused', function () {
    Booking::factory()->approved()->create([
        'resource_id' => $this->resource->id,
        'starts_at' => Carbon::parse('+6 days 13:00'),
        'ends_at' => Carbon::parse('+6 days 15:00'),
    ]);

    $editRequest = $this->booking->editRequests()->create([
        'requested_by' => $this->requester->id,
        'proposed_starts_at' => Carbon::parse('+6 days 13:30'),
        'proposed_ends_at' => Carbon::parse('+6 days 14:30'),
    ]);

    $this->actingAs($this->approver)
        ->post(route('admin.edit-requests.apply', $editRequest))
        ->assertSessionHasErrors('starts_at');

    expect($editRequest->refresh()->status)->toBe(EditRequestStatus::Pending);
});

test('rejecting a change keeps the booking untouched', function () {
    $originalStart = $this->booking->starts_at->toIso8601String();

    $editRequest = $this->booking->editRequests()->create([
        'requested_by' => $this->requester->id,
        'proposed_starts_at' => Carbon::parse('+6 days 13:00'),
        'proposed_ends_at' => Carbon::parse('+6 days 15:00'),
    ]);

    $this->actingAs($this->approver)
        ->post(route('admin.edit-requests.reject', $editRequest), [
            'reason' => 'That slot is being held for the chairperson.',
        ])
        ->assertRedirect();

    expect($editRequest->refresh()->status)->toBe(EditRequestStatus::Rejected)
        ->and($this->booking->refresh()->starts_at->toIso8601String())->toBe($originalStart);
});
