<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\EditRequestStatus;
use App\Models\Booking;
use App\Models\BookingEditRequest;
use App\Models\User;

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
        $booking->loadMissing(['resource.type', 'resource.calendar:id,color', 'editRequests', 'event:id,title', 'reviewer:id,name']);

        return [
            'id' => $booking->id,
            'reference' => $booking->reference(),
            'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
            'resource' => [
                'id' => $booking->resource->id,
                'name' => $booking->resource->name,
                'type' => $booking->resource->type->name,
                'color' => $booking->resource->calendar?->color,
            ],
            'status' => $booking->status->value,
            'status_color' => $booking->status->color(),
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'purpose' => $booking->purpose,
            'attendee_count' => $booking->attendee_count,
            'people' => $booking->people ?? [],
            'rejection_reason' => $booking->rejection_reason,
            'cancellation_reason' => $booking->cancellation_reason,
            // Cancelled by a reviewer rather than by the requester themself.
            'cancelled_by_reviewer' => $booking->cancelled_by !== null && $booking->cancelled_by !== $booking->requested_by,
            'reviewed_by' => $booking->reviewer?->name,
            'reviewed_at' => $booking->reviewed_at?->toIso8601String(),
            'requested_at' => $booking->created_at?->toIso8601String(),
            'has_pending_edit' => $booking->editRequests
                ->contains(fn (BookingEditRequest $er) => $er->status === EditRequestStatus::Pending),
            'can_cancel' => in_array($booking->status->value, ['pending', 'approved'], true),
            'can_request_edit' => $booking->status->value === 'approved',
            'version' => BookingService::versionOf($booking),
            'google' => request()->user()?->id === $booking->requested_by ? $this->googleCopy($booking) : null,
        ];
    }

    /**
     * One booking on its own page, for its requester or an approver of its
     * type: everything on record, plus what this viewer may do with it. The
     * caller has already checked that the viewer may see it.
     *
     * @return array<string, mixed>
     */
    public function detail(Booking $booking, User $viewer): array
    {
        $booking->loadMissing([
            'resource.type',
            'resource.calendar:id,color',
            'requester:id,name,email,division_id',
            'requester.division:id,name,acronym',
            'event:id,title',
            'reviewer:id,name',
            'canceller:id,name',
            'editRequests',
        ]);

        $type = $booking->resource->type;
        $isMine = $booking->requested_by === $viewer->id;
        $pendingChange = $booking->editRequests
            ->first(fn (BookingEditRequest $er) => $er->status === EditRequestStatus::Pending);

        return [
            ...$this->toArray($booking),
            'reference' => $booking->reference(),
            'people' => $booking->people ?? [],
            'type' => [
                'name' => $type->name,
                'slug' => $type->slug,
                'icon' => $type->icon,
                'requires_approval' => $type->requires_approval,
            ],
            'resource' => [
                'id' => $booking->resource->id,
                'name' => $booking->resource->name,
                'location' => $booking->resource->location,
                'capacity' => $booking->resource->capacity,
                'color' => $booking->resource->calendar?->color,
            ],
            'requester' => [
                'name' => $booking->requester?->name,
                'email' => $booking->requester?->email,
                'division' => $booking->requester?->division?->acronym ?? $booking->requester?->division?->name,
            ],
            'cancelled_by' => $booking->canceller?->name,
            'cancelled_at' => $booking->cancelled_at?->toIso8601String(),
            'pending_change' => $pendingChange === null ? null : [
                'starts_at' => $pendingChange->proposed_starts_at?->toIso8601String(),
                'ends_at' => $pendingChange->proposed_ends_at?->toIso8601String(),
                'purpose' => $pendingChange->proposed_purpose,
                'people' => $pendingChange->proposed_people,
                'note' => $pendingChange->note,
                'requested_at' => $pendingChange->created_at?->toIso8601String(),
            ],
            'is_mine' => $isMine,
            'can' => [
                'print' => $booking->status === BookingStatus::Approved,
                'revise' => $isMine && $booking->status === BookingStatus::Pending,
                'request_change' => $isMine
                    && $booking->status === BookingStatus::Approved
                    && $booking->ends_at->isFuture()
                    && $pendingChange === null,
                'cancel' => $viewer->can('cancel', $booking),
                'review' => $viewer->can('approve', $booking),
            ],
            'google' => $isMine ? $this->googleCopy($booking) : null,
        ];
    }

    /**
     * Where the requester's Google copy of this booking stands. Only ever
     * shown to the requester.
     *
     * @return array{state: string, html_link: string|null}|null
     */
    private function googleCopy(Booking $booking): ?array
    {
        $link = $booking->googleEvents()->latest('id')->first();

        return $link === null ? null : ['state' => $link->state, 'html_link' => $link->html_link];
    }
}
