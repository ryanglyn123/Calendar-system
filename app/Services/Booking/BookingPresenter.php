<?php

namespace App\Services\Booking;

use App\Enums\EditRequestStatus;
use App\Models\Booking;
use App\Models\BookingEditRequest;

/**
 * One serialization for a user's own booking row, shared by the bookings
 * API and the section pages' inlined first paint so both produce identical
 * shapes.
 */
class BookingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Booking $booking): array
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
                ->contains(fn (BookingEditRequest $er) => $er->status === EditRequestStatus::Pending),
            'can_cancel' => in_array($booking->status->value, ['pending', 'approved'], true),
            'can_request_edit' => $booking->status->value === 'approved',
        ];
    }
}
