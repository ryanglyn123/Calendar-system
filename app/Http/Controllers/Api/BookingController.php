<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resource_id' => ['required', 'integer', 'exists:resources,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'attendee_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $resource = Resource::query()->with('type')->findOrFail((int) $validated['resource_id']);

        $booking = $this->bookings->request(
            $request->user(),
            $resource,
            Carbon::parse($validated['starts_at'])->utc(),
            Carbon::parse($validated['ends_at'])->utc(),
            $validated['purpose'] ?? null,
            $validated['attendee_count'] ?? null,
            $validated['title'],
        );

        return response()->json(['data' => $this->serialize($booking)], 201);
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

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->bookings->cancel($booking, $request->user(), $validated['reason'] ?? null);

        return response()->json(['data' => $this->serialize($booking->refresh())]);
    }

    public function requestEdit(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('requestEdit', $booking);

        $validated = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'required_with:starts_at', 'after:starts_at'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'attendee_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->editRequests->submit($booking, $request->user(), [
            'starts_at' => isset($validated['starts_at']) ? Carbon::parse($validated['starts_at'])->utc() : null,
            'ends_at' => isset($validated['ends_at']) ? Carbon::parse($validated['ends_at'])->utc() : null,
            'purpose' => $validated['purpose'] ?? null,
            'attendee_count' => $validated['attendee_count'] ?? null,
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
