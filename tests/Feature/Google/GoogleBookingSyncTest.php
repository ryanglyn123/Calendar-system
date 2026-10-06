<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\GoogleBookingEvent;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\EditRequestService;
use App\Services\Google\GoogleBookingSync;
use App\Services\Google\GoogleScopes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Bookings stay the source of truth: only approved bookings reach Google,
 * and nothing done in Google changes a booking.
 */
beforeEach(function () {
    Notification::fake();

    $role = Role::query()->where('name', 'board_room_approver')->firstOrFail();
    $this->approver = User::factory()->create();
    $this->approver->assignRole($role);

    $this->requester = User::factory()->create();
    $this->requester->assignRole('staff');
    $this->account = googleAccountFor($this->requester);

    $this->type = ResourceType::factory()->create(['approver_role_id' => $role->id, 'requires_approval' => true]);
    $this->room = Resource::factory()->create(['resource_type_id' => $this->type->id, 'name' => 'Board Room A']);
    $this->start = Carbon::parse('next monday 09:00', 'Asia/Manila');

    $this->google = [
        // calendars.insert (the app-created Bookings calendar)
        'www.googleapis.com/calendar/v3/calendars' => Http::response(['id' => 'bookings@group.calendar.google.com', 'summary' => 'FDCP Bookings', 'timeZone' => 'Asia/Manila']),
        'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response(['id' => 'fdcpbk', 'etag' => '"p2"', 'htmlLink' => 'https://calendar.google.com/e/1']),
        'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['id' => 'fdcpbk', 'etag' => '"p1"', 'htmlLink' => 'https://calendar.google.com/e/1']),
    ];
});

function requestRoom(object $t): Booking
{
    return app(BookingService::class)->request(
        $t->requester, $t->room, $t->start->copy(), $t->start->copy()->addHours(2), 'Quarterly review', ['Ana', 'Ben'], 'Budget review',
    );
}

test('a pending request is not copied to Google; approval copies it', function () {
    Http::fake($this->google);

    $booking = requestRoom($this);
    Http::assertNothingSent();

    app(BookingService::class)->approve($booking, $this->approver);

    Http::assertSent(function (Request $r) use ($booking) {
        return $r->method() === 'POST'
            && str_ends_with($r->url(), '/events')
            && $r['id'] === GoogleBookingSync::eventId($booking)
            && $r['summary'] === 'Budget review'
            && $r['extendedProperties']['private'][GoogleBookingSync::BOOKING_KEY] === (string) $booking->id
            && str_contains($r['location'], 'Board Room A');
    });

    expect($booking->googleEvents()->first())
        ->state->toBe(GoogleBookingEvent::SYNCED)
        ->etag->toBe('"p1"');
});

test('bookings of a type that needs no approval are copied straight away', function () {
    Http::fake($this->google);
    $this->type->update(['requires_approval' => false]);

    $booking = requestRoom($this);

    expect($booking->status)->toBe(BookingStatus::Approved);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/events'));
});

test('rejecting or cancelling removes the copy from Google', function () {
    Http::fake($this->google);
    $booking = requestRoom($this);
    app(BookingService::class)->approve($booking, $this->approver);

    app(BookingService::class)->cancel($booking->fresh(), $this->requester);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), GoogleBookingSync::eventId($booking)));
    expect($booking->googleEvents()->count())->toBe(0);
});

test('an approved change moves the copy in Google', function () {
    Http::fake($this->google);
    $booking = requestRoom($this);
    app(BookingService::class)->approve($booking, $this->approver);

    $edit = app(EditRequestService::class)->submit($booking->fresh(), $this->requester, [
        // The service takes UTC, as its controller passes it.
        'starts_at' => $this->start->copy()->addHours(3)->utc(),
        'ends_at' => $this->start->copy()->addHours(4)->utc(),
    ]);
    app(EditRequestService::class)->apply($edit, $this->approver);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && Carbon::parse($r['start']['dateTime'])->eq($this->start->copy()->addHours(3)));
});

test('a retried insert that finds the event already there updates it instead of duplicating', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars' => $this->google['www.googleapis.com/calendar/v3/calendars'],
        'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response(['id' => 'x', 'etag' => '"p9"']),
        'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['error' => ['errors' => [['reason' => 'duplicate']]]], 409),
    ]);
    $booking = requestRoom($this);

    app(BookingService::class)->approve($booking, $this->approver);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
    expect($booking->googleEvents()->first()->etag)->toBe('"p9"');
});

test('people without a Google connection, or with copying off, are left alone', function () {
    Http::fake($this->google);
    $this->account->update(['push_bookings' => false]);
    $booking = requestRoom($this);

    app(BookingService::class)->approve($booking, $this->approver);
    Http::assertNothingSent();

    $this->account->update(['push_bookings' => true, 'scopes' => [GoogleScopes::CALENDAR_LIST, GoogleScopes::EVENTS_READONLY]]);
    app(GoogleBookingSync::class)->reconcile($booking->fresh());
    Http::assertNothingSent();
});

test('edits made in Google are noticed but never change the booking', function () {
    Http::fake($this->google);
    $booking = requestRoom($this);
    app(BookingService::class)->approve($booking, $this->approver);
    $link = $booking->googleEvents()->first();

    // The user drags the copy two hours later in Google.
    app(GoogleBookingSync::class)->noticeGoogleChange($this->account, [
        'id' => $link->google_event_id,
        'etag' => '"user-edit"',
        'status' => 'confirmed',
        'start' => ['dateTime' => $booking->starts_at->copy()->addHours(2)->toIso8601String()],
        'end' => ['dateTime' => $booking->ends_at->copy()->addHours(2)->toIso8601String()],
    ]);

    expect($link->fresh()->state)->toBe(GoogleBookingEvent::EDITED_IN_GOOGLE)
        ->and($booking->fresh()->starts_at->eq($booking->starts_at))->toBeTrue();
});

test('a copy deleted in Google stays deleted until the requester asks for it back', function () {
    Http::fake($this->google);
    $booking = requestRoom($this);
    app(BookingService::class)->approve($booking, $this->approver);
    $link = $booking->googleEvents()->first();

    app(GoogleBookingSync::class)->noticeGoogleChange($this->account, ['id' => $link->google_event_id, 'status' => 'cancelled']);
    expect($link->fresh()->state)->toBe(GoogleBookingEvent::REMOVED_IN_GOOGLE);

    // A later reconcile (e.g. a retried job) does not resurrect it...
    Http::fake($this->google);
    app(GoogleBookingSync::class)->reconcile($booking->fresh());
    Http::assertNothingSent();

    // ...until the requester asks.
    $this->actingAs($this->requester)->postJson("/api/v1/bookings/{$booking->id}/google/restore")->assertAccepted();
    expect($link->fresh()->state)->toBe(GoogleBookingEvent::SYNCED);
});

test('only the requester can put a booking back into Google', function () {
    Http::fake($this->google);
    $booking = requestRoom($this);
    app(BookingService::class)->approve($booking, $this->approver);

    $this->actingAs($this->approver)->postJson("/api/v1/bookings/{$booking->id}/google/restore")->assertForbidden();
});

test('the Google copy status is shown to the requester only', function () {
    Http::fake($this->google);
    $booking = requestRoom($this);
    app(BookingService::class)->approve($booking, $this->approver);

    $this->actingAs($this->requester)->get("/bookings/{$booking->id}")
        ->assertInertia(fn ($page) => $page->where('booking.google.state', 'synced'));
    $this->actingAs($this->approver)->get("/bookings/{$booking->id}")
        ->assertInertia(fn ($page) => $page->where('booking.google', null));
});
