<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingApprovalController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
    ) {}

    /**
     * Pending queue scoped to what the signed-in reviewer may approve.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('bookings.approve'), 403);

        $pending = Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->with(['resource.type.approverRole', 'requester'])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Booking $booking) => $request->user()->can('approve', $booking))
            ->values();

        return Inertia::render('admin/bookings/Index', [
            'pending' => $pending->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'resource' => $booking->resource->name,
                'type' => $booking->resource->type->name,
                'requester' => $booking->requester?->name,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'purpose' => $booking->purpose,
                'attendee_count' => $booking->attendee_count,
            ]),
        ]);
    }

    public function approve(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('approve', $booking);

        $this->bookings->approve($booking, $request->user());

        Toast::success('Booking approved.');

        return back();
    }

    public function reject(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('approve', $booking);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->bookings->reject($booking, $request->user(), $validated['reason']);

        Toast::success('Booking rejected.');

        return back();
    }
}
