<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BookingDecidedNotification;
use App\Services\Booking\BookingDocument;
use App\Services\Booking\BookingService;
use App\Services\Booking\EditRequestService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Named people on a booking, from the request form through review and
 * approval to the printed form everyone signs: who may see and print it,
 * what it says, and that every step reads the booking as it is now.
 */
beforeEach(function () {
    $roomRole = Role::query()->where('name', 'board_room_approver')->firstOrFail();
    $vehicleRole = Role::query()->where('name', 'vehicle_approver')->firstOrFail();

    $this->requester = User::factory()->create(['name' => 'Marco Santos']);
    $this->requester->assignRole('staff');
    $this->stranger = User::factory()->create();
    $this->stranger->assignRole('staff');
    $this->roomApprover = User::factory()->create(['name' => 'Lea Cruz']);
    $this->roomApprover->assignRole($roomRole);
    $this->vehicleApprover = User::factory()->create();
    $this->vehicleApprover->assignRole($vehicleRole);

    $this->boardroom = ResourceType::factory()->create(['name' => 'Boardroom', 'slug' => 'board-room', 'icon' => 'presentation', 'approver_role_id' => $roomRole->id]);
    $this->carpool = ResourceType::factory()->create(['name' => 'Carpool', 'slug' => 'vehicle', 'icon' => 'car', 'approver_role_id' => $vehicleRole->id]);
    $this->room = Resource::factory()->create(['resource_type_id' => $this->boardroom->id, 'name' => 'Mezzanine Board Room', 'capacity' => 6]);
    $this->van = Resource::factory()->create(['resource_type_id' => $this->carpool->id, 'name' => 'Toyota Hiace Van 1', 'capacity' => 14]);

    $this->start = Carbon::parse('+3 days 09:00');
});

function peopleRequest(User $user, Resource $resource, Carbon $start, array $people): TestResponse
{
    return test()->actingAs($user)->postJson(route('api.bookings.store'), [
        'resource_id' => $resource->id,
        'title' => 'Budget walkthrough',
        'starts_at' => $start->toIso8601String(),
        'ends_at' => $start->copy()->addHours(2)->toIso8601String(),
        'purpose' => 'Quarterly numbers with Finance',
        'people' => $people,
    ]);
}

// ---- People on the request ---------------------------------------------

test('the names are saved with the booking, tidied, and counted', function () {
    peopleRequest($this->requester, $this->room, $this->start, ['  Ana   Reyes ', "Ben\u{200B} Cruz", 'María Peñaflor'])
        ->assertCreated();

    $booking = Booking::query()->sole();

    expect($booking->people)->toBe(['Ana Reyes', 'Ben Cruz', 'María Peñaflor'])
        ->and($booking->attendee_count)->toBe(3);
});

test('the names are validated row by row', function (array $people, string $field) {
    peopleRequest($this->requester, $this->van, $this->start, $people)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Booking::query()->count())->toBe(0);
})->with([
    'no one listed' => [[], 'people'],
    'an empty row' => [['Ana Reyes', '   '], 'people.1'],
    'no letters' => [['123', 'Ana Reyes'], 'people.0'],
    'too long' => [[str_repeat('a', 101)], 'people.0'],
    'not a name' => [[['nested']], 'people.0'],
    'more than the hard limit' => [array_map(fn ($i) => "Guest {$i}", range(1, 101)), 'people'],
]);

test('a name listed twice is flagged on its second row only, whatever its case or spacing', function () {
    $response = peopleRequest($this->requester, $this->van, $this->start, ['Ana Reyes', 'Ben Cruz', 'ana  REYES'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['people.2' => 'already on the list']);

    expect($response->json('errors'))->not->toHaveKey('people.0');
});

test('the list may not exceed what the room seats', function () {
    peopleRequest($this->requester, $this->room, $this->start, array_map(fn ($i) => "Guest {$i}", range(1, 7)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['people' => 'holds at most 6 people']);
});

// ---- Editing before and after approval -----------------------------------

test('a requester corrects their pending request, and the reviewer sees the new list', function () {
    peopleRequest($this->requester, $this->room, $this->start, ['Ana Reyes'])->assertCreated();
    $booking = Booking::query()->sole();

    $this->actingAs($this->requester)
        ->patchJson(route('api.bookings.update', $booking), [
            'title' => 'Budget walkthrough with DBM',
            'purpose' => 'Numbers and next steps',
            'people' => ['Ana Reyes', 'Ben Cruz'],
        ])
        ->assertOk()
        ->assertJsonPath('data.attendee_count', 2);

    $booking->refresh();

    expect($booking->people)->toBe(['Ana Reyes', 'Ben Cruz'])
        ->and($booking->purpose)->toBe('Numbers and next steps')
        ->and($booking->event->title)->toBe('Budget walkthrough with DBM')
        ->and($booking->status)->toBe(BookingStatus::Pending);

    $this->actingAs($this->roomApprover)
        ->get(route('admin.bookings.show', $booking))
        ->assertInertia(fn ($page) => $page->where('focus.people', ['Ana Reyes', 'Ben Cruz']));
});

test('only the requester may correct a pending request, and only while it is pending', function () {
    peopleRequest($this->requester, $this->room, $this->start, ['Ana Reyes'])->assertCreated();
    $booking = Booking::query()->sole();
    $payload = ['title' => 'Mine now', 'people' => ['Someone Else']];

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    foreach ([$this->stranger, $this->roomApprover, $superAdmin] as $other) {
        $this->actingAs($other)->patchJson(route('api.bookings.update', $booking), $payload)->assertForbidden();
    }

    app(BookingService::class)->approve($booking, $this->roomApprover);

    $this->actingAs($this->requester)
        ->patchJson(route('api.bookings.update', $booking), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'approved while you were editing']);

    expect($booking->fresh()->people)->toBe(['Ana Reyes']);
});

test('nobody approves a list they never saw: a correction makes the reviewer\'s copy stale', function () {
    peopleRequest($this->requester, $this->room, $this->start, ['Ana Reyes'])->assertCreated();
    $booking = Booking::query()->sole();
    $seen = BookingService::versionOf($booking);

    $this->travel(2)->seconds();

    $this->actingAs($this->requester)
        ->patchJson(route('api.bookings.update', $booking), ['title' => 'Budget walkthrough', 'people' => ['Ana Reyes', 'Ben Cruz']])
        ->assertOk();

    $this->actingAs($this->roomApprover)
        ->postJson(route('api.bookings.approve', $booking), ['version' => $seen])
        ->assertConflict();

    // And the requester's own stale copy is refused the same way.
    $this->actingAs($this->requester)
        ->patchJson(route('api.bookings.update', $booking), ['title' => 'x', 'people' => ['Zed'], 'version' => $seen])
        ->assertConflict();

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

test('an approved booking changes its people through a change request', function () {
    $booking = Booking::factory()->approved()->at($this->start)->create([
        'resource_id' => $this->van->id,
        'requested_by' => $this->requester->id,
        'people' => ['Ana Reyes'],
        'attendee_count' => 1,
    ]);

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.edit-requests.store', $booking), ['people' => ['Ana Reyes', 'Ben Cruz'], 'note' => 'Ben joins'])
        ->assertCreated();

    expect($booking->fresh()->people)->toBe(['Ana Reyes']);

    app(EditRequestService::class)->apply($booking->editRequests()->sole(), $this->vehicleApprover);

    expect($booking->fresh()->people)->toBe(['Ana Reyes', 'Ben Cruz'])
        ->and($booking->fresh()->attendee_count)->toBe(2);
});

// ---- The booking page ------------------------------------------------------

test('the requester and that type\'s approvers open the booking; nobody else does', function () {
    peopleRequest($this->requester, $this->room, $this->start, ['Ana Reyes', 'Ben Cruz'])->assertCreated();
    $booking = Booking::query()->sole();

    $this->actingAs($this->requester)
        ->get(route('bookings.show', $booking))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('bookings/Show')
            ->where('booking.reference', $booking->reference())
            ->where('booking.people', ['Ana Reyes', 'Ben Cruz'])
            ->where('booking.status', 'pending')
            ->where('booking.type.slug', 'board-room')
            ->where('booking.can.print', false)
            ->where('booking.can.revise', true)
            ->where('booking.can.review', false));

    $this->actingAs($this->roomApprover)
        ->get(route('bookings.show', $booking))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('booking.can.review', true)
            ->where('booking.can.revise', false));

    // A Carpool approver, another staff member: refused, by any id.
    $this->actingAs($this->vehicleApprover)->get(route('bookings.show', $booking))->assertForbidden();
    $this->actingAs($this->stranger)->get(route('bookings.show', $booking))->assertForbidden();
    $this->actingAs($this->stranger)->get(route('bookings.pdf', $booking))->assertForbidden();
    $this->actingAs($this->requester)->get('/bookings/999999')->assertNotFound();

    auth()->logout();
    $this->get(route('bookings.show', $booking))->assertRedirect(route('login'));

    $booking->delete();
    $this->actingAs($this->requester)->get(route('bookings.show', $booking))->assertNotFound();
});

// ---- Approval, its notice, and the form ------------------------------------

test('approval sends the requester one notice that opens the booking, and opening it clears the notice', function () {
    peopleRequest($this->requester, $this->van, $this->start, ['Ana Reyes'])->assertCreated();
    $booking = Booking::query()->sole();

    $this->actingAs($this->vehicleApprover)->postJson(route('api.bookings.approve', $booking))->assertOk();
    // A second click from a stale page changes nothing and sends nothing.
    $this->actingAs($this->vehicleApprover)->postJson(route('api.bookings.approve', $booking))->assertUnprocessable();

    $notices = $this->requester->notifications()->where('type', BookingDecidedNotification::class)->get();

    expect($notices)->toHaveCount(1)
        ->and($notices[0]->data['title'])->toBe('Booking approved')
        ->and($notices[0]->data['action_url'])->toBe("/bookings/{$booking->id}")
        ->and($notices[0]->data['reference'])->toBe($booking->reference())
        ->and($notices[0]->data['message'])->toContain('Toyota Hiace Van 1');

    $bell = $this->actingAs($this->requester)->getJson(route('api.notifications.index'));
    $bell->assertJsonPath('data.0.booking_status', 'approved')
        ->assertJsonPath('data.0.action_url', "/bookings/{$booking->id}");

    $this->actingAs($this->requester)->get(route('bookings.show', $booking))->assertOk();

    expect($this->requester->unreadNotifications()->count())->toBe(0);
});

test('an approval notice whose booking was cancelled since says so', function () {
    peopleRequest($this->requester, $this->van, $this->start, ['Ana Reyes'])->assertCreated();
    $booking = Booking::query()->sole();

    app(BookingService::class)->approve($booking, $this->vehicleApprover);
    $this->travel(1)->minute();
    app(BookingService::class)->cancel($booking->fresh(), $this->vehicleApprover, 'The van is in for repairs.');

    $this->actingAs($this->requester)
        ->getJson(route('api.notifications.index'))
        ->assertJsonPath('data.1.title', 'Booking approved')
        ->assertJsonPath('data.1.booking_status', 'cancelled')
        ->assertJsonPath('data.0.title', 'Booking cancelled by a reviewer');
});

test('the requester and that type\'s approvers print an approved booking\'s form', function () {
    $booking = Booking::factory()->approved()->at($this->start)->create([
        'resource_id' => $this->room->id,
        'requested_by' => $this->requester->id,
        'reviewed_by' => $this->roomApprover->id,
        'reviewed_at' => now(),
        'people' => ['Ana Reyes', 'Ben Cruz'],
    ]);

    foreach ([$this->requester, $this->roomApprover] as $user) {
        $response = $this->actingAs($user)->get(route('bookings.pdf', $booking));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($response->getContent())->toStartWith('%PDF')
            ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename="'.$booking->reference().'-boardroom-form.pdf"');
    }

    expect($this->actingAs($this->requester)->get(route('bookings.pdf', [$booking, 'download' => 1]))->headers->get('Content-Disposition'))
        ->toStartWith('attachment;');

    $this->actingAs($this->vehicleApprover)->get(route('bookings.pdf', $booking))->assertForbidden();
});

test('pending, rejected and cancelled bookings have no form, not even for a super admin', function (BookingStatus $status) {
    $booking = Booking::factory()->at($this->start)->create([
        'resource_id' => $this->room->id,
        'requested_by' => $this->requester->id,
        'status' => $status,
    ]);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    foreach ([$this->requester, $superAdmin] as $user) {
        $this->actingAs($user)
            ->get(route('bookings.pdf', $booking))
            ->assertRedirect(route('bookings.show', $booking));
    }
})->with([BookingStatus::Pending, BookingStatus::Rejected, BookingStatus::Cancelled]);

test('a form that fails to render sends the viewer back to the booking with a message', function () {
    $booking = Booking::factory()->approved()->at($this->start)->create([
        'resource_id' => $this->room->id,
        'requested_by' => $this->requester->id,
    ]);

    $this->mock(BookingDocument::class)->shouldReceive('render')->andThrow(new RuntimeException('disk full'));

    $this->actingAs($this->requester)
        ->get(route('bookings.pdf', $booking))
        ->assertRedirect(route('bookings.show', $booking));
});

test('the form names every person, with a row each to sign, and says it is approved', function () {
    $people = ['Ana Reyes', 'María Clara Dela Cruz-Villanueva y Peñaflor de los Santos', ...array_map(fn ($i) => "Guest {$i}", range(3, 40))];

    $booking = Booking::factory()->approved()->at($this->start)->create([
        'resource_id' => $this->van->id,
        'requested_by' => $this->requester->id,
        'reviewed_by' => $this->vehicleApprover->id,
        'reviewed_at' => now(),
        'people' => $people,
    ]);

    $html = app(BookingDocument::class)->html($booking);

    foreach ($people as $name) {
        expect($html)->toContain(e($name));
    }

    expect($html)->toContain($booking->reference())
        ->toContain('APPROVED')
        ->toContain('Passengers and signatures')
        ->toContain('Toyota Hiace Van 1')
        ->and(substr_count($html, '<td class="num">'))->toBe(40);

    // And it really is a PDF, over several pages.
    expect(app(BookingDocument::class)->render($booking))->toStartWith('%PDF');
});

test('an older booking without names prints blank rows to fill in', function () {
    $booking = Booking::factory()->approved()->at($this->start)->create([
        'resource_id' => $this->room->id,
        'requested_by' => $this->requester->id,
        'people' => null,
        'attendee_count' => 3,
    ]);

    $html = app(BookingDocument::class)->html($booking);

    expect($html)->toContain('Names were not recorded')
        ->toContain('Attendees and signatures')
        ->and(substr_count($html, '<td class="num">'))->toBe(3);
});
