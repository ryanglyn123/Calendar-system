<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\EditRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingEditRequest;
use App\Services\Admin\AdminDashboard;
use App\Services\Booking\EditRequestService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EditRequestController extends Controller
{
    public function __construct(
        private readonly EditRequestService $editRequests,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('bookings.approve'), 403);

        $pending = BookingEditRequest::query()
            ->where('status', EditRequestStatus::Pending->value)
            ->with([
                'booking.resource.type.approverRole',
                'booking.resource.calendar:id,color',
                'booking.event:id,title',
                'requester.division:id,name,acronym',
            ])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (BookingEditRequest $editRequest) => $request->user()->can('approve', $editRequest->booking))
            ->values();

        return Inertia::render('admin/edit-requests/Index', [
            'today' => now(AdminDashboard::timezone())->toDateString(),
            'pending' => $pending->map(fn (BookingEditRequest $editRequest) => $this->present($editRequest)),
        ]);
    }

    /**
     * One change request as its reviewer needs it: the booking as it stands,
     * what would change, and the day the booking would land on, so a moved
     * slot can be judged against everything else held there.
     *
     * @return array<string, mixed>
     */
    private function present(BookingEditRequest $editRequest): array
    {
        $booking = $editRequest->booking;
        $resource = $booking->resource;
        $start = $editRequest->proposed_starts_at ?? $booking->starts_at;
        $end = $editRequest->proposed_ends_at ?? $booking->ends_at;
        $movesTime = $editRequest->proposed_starts_at !== null || $editRequest->proposed_ends_at !== null;

        // The day the booking would sit on once applied, in office time.
        $dayStart = $start->copy()->timezone(AdminDashboard::timezone())->startOfDay();

        $day = Booking::query()
            ->where('resource_id', $booking->resource_id)
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value])
            ->where('starts_at', '<', $dayStart->copy()->addDay())
            ->where('ends_at', '>', $dayStart)
            ->with(['event:id,title', 'requester:id,name'])
            ->orderBy('starts_at')
            ->get();

        // Apply re-checks this; shown up front so nobody clicks into a refusal.
        $clash = $movesTime
            ? $day->first(fn (Booking $other) => $other->id !== $booking->id
                && $other->starts_at < $end && $other->ends_at > $start)
            : null;

        return [
            'id' => $editRequest->id,
            'booking_id' => $booking->id,
            'reference' => $booking->reference(),
            'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
            'type' => $resource?->type?->name,
            'resource' => [
                'name' => $resource?->name,
                'location' => $resource?->location,
                'capacity' => $resource?->capacity,
                'color' => $resource?->calendar?->color,
            ],
            'requester' => [
                'name' => $editRequest->requester?->name,
                'division' => $editRequest->requester?->division?->acronym ?? $editRequest->requester?->division?->name,
            ],
            'requested_at' => $editRequest->created_at?->toIso8601String(),
            'note' => $editRequest->note,
            'current' => [
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'purpose' => $booking->purpose,
                'attendee_count' => $booking->attendee_count,
                'people' => $booking->people ?? [],
            ],
            'proposed' => [
                'starts_at' => $editRequest->proposed_starts_at?->toIso8601String(),
                'ends_at' => $editRequest->proposed_ends_at?->toIso8601String(),
                'purpose' => $editRequest->proposed_purpose,
                'attendee_count' => $editRequest->proposed_attendee_count,
                'people' => $editRequest->proposed_people,
            ],
            // A moved slot that has already started can't be applied.
            'passed' => $movesTime && $start->isPast(),
            'clash' => $clash === null ? null : [
                'title' => $clash->event->title ?? $clash->purpose ?? 'Booking',
                'status' => $clash->status->value,
                'requester' => $clash->requester?->name,
                'starts_at' => $clash->starts_at->toIso8601String(),
                'ends_at' => $clash->ends_at->toIso8601String(),
            ],
            'day' => [
                'date' => $dayStart->toDateString(),
                'bookings' => $day->map(fn (Booking $other) => [
                    'id' => $other->id,
                    'title' => $other->event->title ?? $other->purpose ?? 'Booking',
                    'status' => $other->status->value,
                    'requester' => $other->requester?->name,
                    'starts_at' => $other->starts_at->toIso8601String(),
                    'ends_at' => $other->ends_at->toIso8601String(),
                ])->values()->all(),
            ],
        ];
    }

    public function apply(Request $request, BookingEditRequest $editRequest): RedirectResponse
    {
        $this->authorize('approve', $editRequest->booking);

        $this->editRequests->apply($editRequest, $request->user());

        Toast::success('Change applied.');

        return back();
    }

    public function reject(Request $request, BookingEditRequest $editRequest): RedirectResponse
    {
        $this->authorize('approve', $editRequest->booking);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->editRequests->reject($editRequest, $request->user(), $validated['reason']);

        Toast::success('Change rejected.');

        return back();
    }
}
