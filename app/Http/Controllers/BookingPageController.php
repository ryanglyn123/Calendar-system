<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingDecidedNotification;
use App\Notifications\EditRequestDecidedNotification;
use App\Services\Booking\BookingDocument;
use App\Services\Booking\BookingPresenter;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * One booking on its own page: where a requester lands from "your booking
 * was approved", and where requester and approvers alike print its form.
 * Both routes check the booking policy, so a guessed id gets a 403.
 */
class BookingPageController extends Controller
{
    public function show(Request $request, Booking $booking, BookingPresenter $presenter): Response
    {
        $this->authorize('view', $booking);

        $this->settleDecisions($request->user(), $booking);

        return Inertia::render('bookings/Show', [
            'booking' => $presenter->detail($booking, $request->user()),
        ]);
    }

    /**
     * The printable booking form, rendered from the row as it is now. Only
     * an approved booking has one: a pending, rejected or cancelled booking
     * must not produce paper that reads like a confirmation. Checked here
     * rather than in the policy, which super admins pass unconditionally.
     */
    public function pdf(Request $request, Booking $booking, BookingDocument $document): HttpResponse|RedirectResponse
    {
        $this->authorize('view', $booking);

        $booking = $booking->fresh() ?? abort(404);

        if ($booking->status !== BookingStatus::Approved) {
            Toast::error("Only approved bookings have a booking form. This one is {$booking->status->value}.");

            return to_route('bookings.show', $booking);
        }

        try {
            $pdf = $document->render($booking);
        } catch (Throwable $e) {
            report($e);
            Toast::error('The booking form could not be generated. Try again in a moment.');

            return to_route('bookings.show', $booking);
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$document->filename($booking)}\"",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Having opened the booking, its requester has seen how it turned out. */
    private function settleDecisions(User $user, Booking $booking): void
    {
        $user->unreadNotifications()
            ->whereIn('type', [BookingDecidedNotification::class, EditRequestDecidedNotification::class])
            ->where('data->booking_id', $booking->id)
            ->update(['read_at' => now()]);
    }
}
