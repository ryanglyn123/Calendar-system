<?php

namespace App\Http\Controllers\Api;

use App\Enums\EditRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Resource;
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
        $bookings = Booking::query()
            ->where('requested_by', $request->user()->id)
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
        $booking->loadMissing(['resource.type', 'editRequests']);

        return [
            'id' => $booking->id,
            'resource' => [
                'id' => $booking->resource->id,
                'name' => $booking->resource->name,
                'type' => $booking->resource->type->name,
            ],
            'status' => $booking->status->value,
            'status_color' => $booking->status->color(),
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'purpose' => $booking->purpose,
            'attendee_count' => $booking->attendee_count,
            'rejection_reason' => $booking->rejection_reason,
            'has_pending_edit' => $booking->editRequests
                ->contains(fn ($er) => $er->status === EditRequestStatus::Pending),
            'can_cancel' => in_array($booking->status->value, ['pending', 'approved'], true),
            'can_request_edit' => $booking->status->value === 'approved',
        ];
    }
}
