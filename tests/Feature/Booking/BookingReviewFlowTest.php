<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BookingRequestedNotification;
use App\Services\Booking\BookingService;
use Illuminate\Support\Carbon;

/**
 * Request -> approver notice -> review page -> decision, for Boardroom and
 * Carpool alike: every link lands on the right booking, notices and counts
 * follow the booking's real state, and only that type's reviewers get in.
 */
beforeEach(function () {
    $roomRole = Role::query()->where('name', 'board_room_approver')->firstOrFail();
    $vehicleRole = Role::query()->where('name', 'vehicle_approver')->firstOrFail();

    $this->requester = User::factory()->create(['name' => 'Marco Santos']);
    $this->requester->assignRole('staff');

    $this->roomApprover = User::factory()->create();
    $this->roomApprover->assignRole($roomRole);
    $this->otherRoomApprover = User::factory()->create();
    $this->otherRoomApprover->assignRole($roomRole);
    $this->vehicleApprover = User::factory()->create();
    $this->vehicleApprover->assignRole($vehicleRole);

    $this->boardroom = ResourceType::factory()->create(['name' => 'Boardroom', 'slug' => 'board-room', 'approver_role_id' => $roomRole->id]);
    $this->carpool = ResourceType::factory()->create(['name' => 'Carpool', 'slug' => 'vehicle', 'approver_role_id' => $vehicleRole->id]);
    $this->room = Resource::factory()->create(['resource_type_id' => $this->boardroom->id, 'name' => 'Mezzanine Board Room', 'capacity' => 12]);
    $this->van = Resource::factory()->create(['resource_type_id' => $this->carpool->id, 'name' => 'Toyota Hiace Van 1', 'capacity' => 14]);

    $this->start = Carbon::parse('+3 days 09:00');
});

function reviewRequest(User $user, Resource $resource, Carbon $start, string $title = 'Budget review'): Booking
{
    test()->actingAs($user)->postJson(route('api.bookings.store'), [
        'resource_id' => $resource->id,
        'title' => $title,
        'starts_at' => $start->toIso8601String(),
        'ends_at' => $start->copy()->addHours(2)->toIso8601String(),
        'purpose' => 'Quarterly numbers with Finance',
        'people' => ['Ana Reyes', 'Ben Cruz', 'Carla Lim', 'Dan Tan', 'Ela Go', 'Fe Uy'],
    ])->assertSuccessful();

    return Booking::query()->latest('id')->firstOrFail();
}

function reviewNotices(User $user)
{
    return $user->notifications()->where('type', BookingRequestedNotification::class)->get();
}

test('a request notifies only its own type\'s reviewers, with a link to that booking', function () {
    $room = reviewRequest($this->requester, $this->room, $this->start);
    $van = reviewRequest($this->requester, $this->van, $this->start, 'Site visit');

    $roomNotice = reviewNotices($this->roomApprover)->sole();
    expect($roomNotice->data['booking_id'])->toBe($room->id)
        ->and($roomNotice->data['title'])->toBe('Boardroom request needs review')
        ->and($roomNotice->data['action_url'])->toBe("/admin/bookings/{$room->id}");

    $vanNotice = reviewNotices($this->vehicleApprover)->sole();
    expect($vanNotice->data['booking_id'])->toBe($van->id)
        ->and($vanNotice->data['title'])->toBe('Carpool request needs review')
        ->and($vanNotice->data['action_url'])->toBe("/admin/bookings/{$van->id}");
});

test('the review page shows the request, its type and every conflict', function () {
    $held = Booking::factory()->approved()->at($this->start->copy())->create(['resource_id' => $this->room->id]);
    // Placed directly: the API refuses a request into a held slot, but
    // requests made before the approval can still sit in it.
    $rival = Booking::factory()->at($this->start->copy()->addHour())->create(['resource_id' => $this->room->id]);

    $this->actingAs($this->roomApprover)
        ->get(route('admin.bookings.show', $rival))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/bookings/Index')
            ->where('focus.id', $rival->id)
            ->where('focus.status', 'pending')
            ->where('focus.type.slug', 'board-room')
            ->where('focus.type.name', 'Boardroom')
            ->where('focus.resource.name', 'Mezzanine Board Room')
            // Its "manage in the calendar" link opens on this room.
            ->where('focus.resource.id', $this->room->id)
            ->has('focus.version')
            ->where('focus.conflicts.0.id', $held->id)
            ->where('focus.conflicts.0.status', 'approved')
            // The schedule opens on the request's own day.
            ->where('schedule.date', $rival->starts_at->timezone('Asia/Manila')->toDateString()));
});

test('a request decided by someone else shows that outcome, not live buttons', function () {
    $booking = reviewRequest($this->requester, $this->room, $this->start);

    app(BookingService::class)->approve($booking, $this->otherRoomApprover);

    $this->actingAs($this->roomApprover)
        ->get(route('admin.bookings.show', $booking))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('focus.status', 'approved')
            ->where('focus.reviewed_by', $this->otherRoomApprover->name));
});

test('unknown, malformed and removed review links land on a page that says so', function () {
    foreach (['999999', 'abc'] as $id) {
        $this->actingAs($this->roomApprover)
            ->get("/admin/bookings/{$id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('focus.missing', true));
    }

    $booking = reviewRequest($this->requester, $this->room, $this->start);
    $booking->delete();

    $this->actingAs($this->roomApprover)
        ->get(route('admin.bookings.show', $booking->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('focus.id', $booking->id)
            ->where('focus.removed', true)
            ->where('focus.conflicts', []));
});

test('only reviewers of that type can open a review page', function () {
    $booking = reviewRequest($this->requester, $this->room, $this->start);

    // A Carpool reviewer must not read a Boardroom request.
    $this->actingAs($this->vehicleApprover)->get(route('admin.bookings.show', $booking))->assertForbidden();
    // The requester (no admin access) cannot open the review page.
    $this->actingAs($this->requester)->get(route('admin.bookings.show', $booking))->assertForbidden();
    // Nor approve through the admin endpoint.
    $this->actingAs($this->vehicleApprover)->post(route('admin.bookings.approve', $booking))->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->get(route('admin.bookings.show', $booking))->assertRedirect(route('login'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

test('deciding settles every reviewer\'s notice for that request', function () {
    $booking = reviewRequest($this->requester, $this->room, $this->start);

    expect(reviewNotices($this->roomApprover)->sole()->read_at)->toBeNull()
        ->and(reviewNotices($this->otherRoomApprover)->sole()->read_at)->toBeNull();

    $this->actingAs($this->otherRoomApprover)
        ->from(route('admin.bookings.show', $booking))
        ->post(route('admin.bookings.approve', $booking), ['version' => BookingService::versionOf($booking)])
        ->assertRedirect(route('admin.bookings.show', $booking));

    expect(reviewNotices($this->roomApprover)->sole()->read_at)->not->toBeNull()
        ->and(reviewNotices($this->otherRoomApprover)->sole()->read_at)->not->toBeNull()
        ->and($booking->fresh()->status)->toBe(BookingStatus::Approved);
});

test('a withdrawn request stops asking for review', function () {
    $booking = reviewRequest($this->requester, $this->room, $this->start);

    $this->actingAs($this->requester)
        ->postJson(route('api.bookings.cancel', $booking))
        ->assertOk();

    expect(reviewNotices($this->roomApprover)->sole()->read_at)->not->toBeNull();
});

test('the notification list reports live state and heals stale notices', function () {
    $booking = reviewRequest($this->requester, $this->room, $this->start);

    // A notice from before this release: generic link, still unread, while
    // the booking was decided out of band.
    $notice = reviewNotices($this->roomApprover)->sole();
    $notice->update(['data' => [...$notice->data, 'action_url' => '/admin/bookings']]);
    Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Rejected->value]);

    $response = $this->actingAs($this->roomApprover)
        ->getJson(route('api.notifications.index'))
        ->assertOk();

    $item = collect($response->json('data'))->firstWhere('id', $notice->id);

    expect($item['action_url'])->toBe("/admin/bookings/{$booking->id}")
        ->and($item['booking_status'])->toBe('rejected')
        ->and($item['read_at'])->not->toBeNull()
        ->and($response->json('unread_count'))->toBe(0)
        ->and($notice->fresh()->read_at)->not->toBeNull();
});

test('a decision made on stale data comes back as a form error', function () {
    $booking = reviewRequest($this->requester, $this->room, $this->start);
    $seen = BookingService::versionOf($booking);

    $this->travel(2)->seconds();
    $booking->update(['purpose' => 'Changed meanwhile']);

    $this->actingAs($this->roomApprover)
        ->from(route('admin.bookings.show', $booking))
        ->post(route('admin.bookings.approve', $booking), ['version' => $seen])
        ->assertRedirect(route('admin.bookings.show', $booking))
        ->assertSessionHasErrors('status');

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

test('a second click on an already decided request is refused cleanly', function () {
    $booking = reviewRequest($this->requester, $this->room, $this->start);

    $this->actingAs($this->roomApprover)->post(route('admin.bookings.approve', $booking))->assertSessionHasNoErrors();
    $this->actingAs($this->otherRoomApprover)
        ->post(route('admin.bookings.reject', $booking), ['reason' => 'Room is reserved that day.'])
        ->assertSessionHasErrors('status');

    expect($booking->fresh()->status)->toBe(BookingStatus::Approved);
});

test('every page tells a reviewer what waits on them, by type', function () {
    reviewRequest($this->requester, $this->room, $this->start);
    reviewRequest($this->requester, $this->room, $this->start->copy()->addDay(), 'Board prep');
    reviewRequest($this->requester, $this->van, $this->start, 'Site visit');
    // One whose time has passed still waits, but sorts last.
    $past = Booking::factory()->at(Carbon::parse('-2 days 09:00'))->create(['resource_id' => $this->room->id]);

    $this->actingAs($this->roomApprover)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('reviewQueue.total', 3)
            ->has('reviewQueue.types', 1)
            ->where('reviewQueue.types.0.slug', 'board-room')
            ->where('reviewQueue.items.0.type', 'board-room')
            ->where('reviewQueue.items.0.passed', false)
            ->where('reviewQueue.items.2.id', $past->id)
            ->where('reviewQueue.items.2.passed', true));

    $super = User::factory()->create();
    $super->assignRole('super_admin');

    $this->actingAs($super)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('reviewQueue.total', 4)
            ->where('reviewQueue.types', fn ($types) => collect($types)->pluck('count', 'slug')->only(['board-room', 'vehicle'])->all() === ['board-room' => 3, 'vehicle' => 1]));

    $this->actingAs($this->requester)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('reviewQueue', null));
});

test('the count drops for every reviewer the moment one decides', function () {
    $booking = reviewRequest($this->requester, $this->room, $this->start);

    expect($this->actingAs($this->roomApprover)->getJson(route('api.notifications.index'))->json('review.total'))->toBe(1);

    app(BookingService::class)->reject($booking, $this->otherRoomApprover, 'Room is reserved that day.');

    expect($this->actingAs($this->roomApprover)->getJson(route('api.notifications.index'))->json('review.total'))->toBe(0);
});

test('the section button counts past requests too and opens a lone one directly', function () {
    $past = Booking::factory()->at(Carbon::parse('-2 days 09:00'))->create(['resource_id' => $this->room->id]);

    $this->actingAs($this->roomApprover)
        ->get(route('resources.section', $this->boardroom->slug))
        ->assertInertia(fn ($page) => $page
            ->where('review.count', 1)
            ->where('review.booking', $past->id));

    reviewRequest($this->requester, $this->room, $this->start);

    $this->actingAs($this->roomApprover)
        ->get(route('resources.section', $this->boardroom->slug))
        ->assertInertia(fn ($page) => $page
            ->where('review.count', 2)
            ->where('review.booking', null));
});

test('a section page lists its waiting requests to that type\'s reviewers only', function () {
    $first = reviewRequest($this->requester, $this->room, $this->start);
    reviewRequest($this->requester, $this->room, $this->start->copy()->addHours(3), 'Later');
    reviewRequest($this->requester, $this->van, $this->start, 'Site visit');

    $this->actingAs($this->roomApprover)
        ->get(route('resources.section', 'board-room'))
        ->assertInertia(fn ($page) => $page
            ->where('review.count', 2)
            ->where('review.booking', null)
            ->has('review.items', 2)
            ->where('review.items.0.id', $first->id)
            ->where('review.items.0.resource_id', $this->room->id)
            ->where('review.items.0.clash', null)
            ->where('review.items.1.title', 'Later'));

    $this->actingAs($this->requester)
        ->get(route('resources.section', 'board-room'))
        ->assertInertia(fn ($page) => $page->where('review', null));
});

test('each section knows who manages it: that type\'s approvers and super admins, nobody else', function () {
    // The page opens in its manage mode (own default view, review list,
    // decision buttons) only where the server hands it the review queue.
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $manages = fn (User $user, string $slug) => $this->actingAs($user)
        ->get(route('resources.section', $slug))
        ->assertOk()
        ->inertiaProps('review') !== null;

    expect($manages($this->roomApprover, 'board-room'))->toBeTrue()
        ->and($manages($this->roomApprover, 'vehicle'))->toBeFalse()
        ->and($manages($this->vehicleApprover, 'vehicle'))->toBeTrue()
        ->and($manages($this->vehicleApprover, 'board-room'))->toBeFalse()
        ->and($manages($this->requester, 'board-room'))->toBeFalse()
        ->and($manages($this->requester, 'vehicle'))->toBeFalse()
        ->and($manages($superAdmin, 'board-room'))->toBeTrue()
        ->and($manages($superAdmin, 'vehicle'))->toBeTrue();

    // And the calendar's own decision endpoint holds the same line.
    $van = reviewRequest($this->requester, $this->van, $this->start, 'Site visit');

    $this->actingAs($this->roomApprover)->postJson(route('api.bookings.approve', $van))->assertForbidden();
    $this->actingAs($this->requester)->postJson(route('api.bookings.approve', $van))->assertForbidden();
    $this->actingAs($this->vehicleApprover)->postJson(route('api.bookings.approve', $van))->assertSuccessful();
});
