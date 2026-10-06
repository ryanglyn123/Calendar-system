<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Jobs\PushBookingToGoogle;
use App\Jobs\SyncGoogleCalendarList;
use App\Models\Booking;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleFreeBusy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Google Calendar actions the calendar and booking screens call. */
class GoogleCalendarController extends Controller
{
    /**
     * The signed-in user's busy times in Google Calendar, as a booking
     * hint. Only ever the caller's own account.
     */
    public function busy(Request $request, GoogleFreeBusy $freeBusy): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $start = CarbonImmutable::parse($validated['start']);
        $end = CarbonImmutable::parse($validated['end']);

        abort_if($start->diffInDays($end) > 31, 422, 'Ask for at most a month at a time.');

        try {
            $busy = $freeBusy->busy($request->user(), $start, $end);
        } catch (GoogleApiException $e) {
            // A hint, never a blocker: say why it is missing and move on.
            return response()->json(['connected' => true, 'data' => [], 'error' => $e->error->message()]);
        }

        return response()->json(['connected' => $busy !== null, 'data' => $busy ?? []]);
    }

    /** "Sync now" from the calendar panel. */
    public function sync(Request $request): JsonResponse
    {
        $account = $request->user()->googleAccount;

        abort_if($account === null || ! $account->isActive(), 409, 'Reconnect Google Calendar first.');

        SyncGoogleCalendarList::dispatch($account->id);

        return response()->json(['queued' => true], 202);
    }

    /**
     * Put a booking back into Google Calendar after it was deleted or
     * changed there. The booking's own version always wins.
     */
    public function restoreBooking(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->requested_by === $request->user()->id, 403);
        abort_unless($booking->status === BookingStatus::Approved, 422, 'Only approved bookings go to Google Calendar.');
        abort_unless($request->user()->googleAccount?->canPushBookings(), 409, 'Turn on "Add approved bookings" in Google Calendar settings first.');

        PushBookingToGoogle::dispatch($booking->id, restore: true);

        return response()->json(['queued' => true], 202);
    }
}
