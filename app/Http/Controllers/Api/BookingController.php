<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\RequestBookingChangeRequest;
use App\Http\Requests\Booking\ReviseBookingRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Resource;
use App\Services\Booking\BookingPresenter;
use App\Services\Booking\BookingService;
use App\Services\Booking\EditRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly EditRequestService $editRequests,
        private readonly BookingPresenter $presenter,
    ) {}

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $resource = Resource::query()->with('type')->findOrFail((int) $validated['resource_id']);

        $booking = $this->bookings->request(
            $request->user(),
            $resource,
            Carbon::parse($validated['starts_at'])->utc(),
            Carbon::parse($validated['ends_at'])->utc(),
            $validated['purpose'] ?? null,
            $validated['people'],
            $validated['title'],
        );

        // A resubmitted form gets its first booking back rather than a clash.
        return response()->json(['data' => $this->serialize($booking)], $booking->wasRecentlyCreated ? 201 : 200);
    }

    public function mine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resource_type_id' => ['sometimes', 'integer'],
        ]);

        $bookings = Booking::query()
            ->where('requested_by', $request->user()->id)
            ->when(
                isset($validated['resource_type_id']),
                fn ($query) => $query->whereHas(
                    'resource',
                    fn ($q) => $q->where('resource_type_id', (int) $validated['resource_type_id']),
                ),
            )
            ->with(['resource.type', 'editRequests'])
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $bookings->map(fn (Booking $booking) => $this->serialize($booking)),
        ]);
    }

    /**
     * Reviewers decide from the calendar. `version` is the booking version
     * the reviewer was looking at; a stale one is refused with 409.
     */
    public function approve(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('approve', $booking);

        $validated = $request->validate([
            'version' => ['nullable', 'string'],
        ]);

        $this->bookings->approve($booking, $request->user(), $validated['version'] ?? null);

        return response()->json(['data' => $this->serialize($booking->refresh())]);
    }

    public function reject(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('approve', $booking);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'version' => ['nullable', 'string'],
        ]);

        $this->bookings->reject($booking, $request->user(), $validated['reason'], $validated['version'] ?? null);

        return response()->json(['data' => $this->serialize($booking->refresh())]);
    }

    /**
     * Requesters withdraw their own bookings; reviewers may cancel anyone's,
     * but must tell the requester why.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('cancel', $booking);

        $byReviewer = $booking->requested_by !== $request->user()->id;

        $validated = $request->validate([
            'reason' => $byReviewer
                ? ['required', 'string', 'min:10', 'max:1000']
                : ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'string'],
        ]);

        $this->bookings->cancel($booking, $request->user(), $validated['reason'] ?? null, $validated['version'] ?? null);

        return response()->json(['data' => $this->serialize($booking->refresh())]);
    }

    /**
     * The requester corrects their own request while it waits for review.
     */
    public function update(ReviseBookingRequest $request, Booking $booking): JsonResponse
    {
        $validated = $request->validated();

        $this->bookings->revise($booking, $request->user(), [
            'title' => $validated['title'],
            'purpose' => $validated['purpose'] ?? null,
            'people' => $validated['people'],
        ], $validated['version'] ?? null);

        return response()->json(['data' => $this->serialize($booking->refresh())]);
    }

    public function requestEdit(RequestBookingChangeRequest $request, Booking $booking): JsonResponse
    {
        $validated = $request->validated();

        $this->editRequests->submit($booking, $request->user(), [
            'starts_at' => isset($validated['starts_at']) ? Carbon::parse($validated['starts_at'])->utc() : null,
            'ends_at' => isset($validated['ends_at']) ? Carbon::parse($validated['ends_at'])->utc() : null,
            'purpose' => $validated['purpose'] ?? null,
            'people' => $validated['people'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($booking->refresh())], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Booking $booking): array
    {
        return $this->presenter->toArray($booking);
    }
}
