<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ResourceType;
use App\Models\User;
use App\Notifications\BookingRequestedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * What waits on a reviewer, read live from the bookings table every time,
 * so a count or a link never outlives the request behind it. Reviewers see
 * the resource types whose approver role they hold; super admins see all.
 */
class ReviewQueue
{
    /** Requests the attention list names; the rest wait on the queue page. */
    public const PREVIEW = 6;

    /** @return Collection<int, ResourceType> */
    public function typesFor(User $user): Collection
    {
        if (! $user->can('bookings.approve')) {
            return new Collection;
        }

        return ResourceType::query()
            ->active()
            ->when(
                ! $user->hasRole('super_admin'),
                fn ($query) => $query->whereIn('approver_role_id', $user->roles->modelKeys()),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'icon']);
    }

    /**
     * @return array{total: int, types: list<array<string, mixed>>, items: list<array<string, mixed>>}|null
     *                                                                                                      null for people who review nothing
     */
    public function summary(User $user): ?array
    {
        $types = $this->typesFor($user)->keyBy('id');

        if ($types->isEmpty()) {
            return null;
        }

        $pending = $this->pendingIn($types->keys()->all());
        $ordered = $this->decidableFirst($pending);

        return [
            'total' => $pending->count(),
            'types' => $types->values()->map(fn (ResourceType $type) => [
                'slug' => $type->slug,
                'name' => $type->name,
                'icon' => $type->icon,
                'count' => $pending->filter(fn (Booking $b) => $b->resource->resource_type_id === $type->id)->count(),
            ])->all(),
            // ponytail: two clash queries per previewed request (at most PREVIEW).
            'items' => $ordered->take(self::PREVIEW)
                ->map(fn (Booking $booking) => $this->item($booking) + ['type' => $types[$booking->resource->resource_type_id]->slug])
                ->all(),
        ];
    }

    /**
     * One type's waiting requests, for its own calendar page.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function forType(ResourceType $type, int $limit = 8): array
    {
        $pending = $this->pendingIn([$type->id]);

        return [
            'count' => $pending->count(),
            'items' => $this->decidableFirst($pending)->take($limit)
                ->map(fn (Booking $booking) => $this->item($booking))
                ->all(),
        ];
    }

    /** @return Collection<int, Booking> */
    private function pendingIn(array $typeIds): Collection
    {
        return Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->whereHas('resource', fn ($query) => $query->whereIn('resource_type_id', $typeIds))
            ->with(['resource:id,name,resource_type_id', 'requester:id,name', 'event:id,title'])
            ->get();
    }

    /**
     * Still decidable first, soonest first; ones whose time has passed last.
     *
     * @param  Collection<int, Booking>  $pending
     * @return Collection<int, Booking>
     */
    private function decidableFirst(Collection $pending): Collection
    {
        $now = now();

        return $pending
            ->sortBy(fn (Booking $booking) => [$booking->ends_at->lte($now) ? 1 : 0, $booking->starts_at->getTimestamp()])
            ->values();
    }

    /** @return array<string, mixed> */
    private function item(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
            'resource' => $booking->resource->name,
            'resource_id' => $booking->resource_id,
            'requester' => $booking->requester?->name,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'passed' => $booking->ends_at->lte(now()),
            'clash' => $this->clash($booking),
        ];
    }

    /**
     * Everything a reviewer needs to decide one request, whatever state it
     * is in now (a link can be opened after someone else has decided).
     *
     * @return array<string, mixed>
     */
    public function detail(Booking $booking): array
    {
        $booking->loadMissing([
            'resource.type',
            'resource.calendar:id,color',
            'requester.division:id,name,acronym',
            'event:id,title',
            'reviewer:id,name',
            'canceller:id,name',
        ]);

        $active = in_array($booking->status, [BookingStatus::Pending, BookingStatus::Approved], true)
            && ! $booking->trashed();

        $conflicts = $active
            ? Booking::query()
                ->blocking($booking->resource_id, $booking->starts_at, $booking->ends_at)
                ->whereKeyNot($booking->id)
                ->with(['event:id,title', 'requester:id,name'])
                ->orderBy('starts_at')
                ->get()
            : new Collection;

        $type = $booking->resource?->type;

        return [
            'id' => $booking->id,
            'reference' => $booking->reference(),
            'version' => BookingService::versionOf($booking),
            'status' => $booking->status->value,
            'removed' => $booking->trashed(),
            'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
            'purpose' => $booking->purpose,
            'attendee_count' => $booking->attendee_count,
            // Names in the requester's order; older bookings only kept a count.
            'people' => $booking->people ?? [],
            'type' => $type === null ? null : [
                'slug' => $type->slug,
                'name' => $type->name,
                'icon' => $type->icon,
            ],
            'resource' => [
                'id' => $booking->resource_id,
                'name' => $booking->resource?->name,
                'location' => $booking->resource?->location,
                'capacity' => $booking->resource?->capacity,
                'color' => $booking->resource?->calendar?->color,
            ],
            'requester' => [
                'name' => $booking->requester?->name,
                'email' => $booking->requester?->email,
                'division' => $booking->requester?->division?->acronym ?? $booking->requester?->division?->name,
            ],
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'requested_at' => $booking->created_at?->toIso8601String(),
            'reviewed_by' => $booking->reviewer?->name,
            'reviewed_at' => $booking->reviewed_at?->toIso8601String(),
            'rejection_reason' => $booking->rejection_reason,
            'cancelled_by' => $booking->canceller?->name,
            'cancelled_at' => $booking->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $booking->cancellation_reason,
            'conflicts' => $conflicts->map(fn (Booking $other) => [
                'id' => $other->id,
                'title' => $other->event->title ?? $other->purpose ?? 'Booking',
                'status' => $other->status->value,
                'requester' => $other->requester?->name,
                'starts_at' => $other->starts_at->toIso8601String(),
                'ends_at' => $other->ends_at->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Once a request is decided (by anyone), its "approval needed" notices
     * stop asking: every reviewer's copy is marked read.
     */
    public function settle(Booking $booking): void
    {
        DatabaseNotification::query()
            ->where('type', BookingRequestedNotification::class)
            ->whereNull('read_at')
            ->where('data->booking_id', $booking->id)
            ->update(['read_at' => now()]);
    }

    /** 'approved' when an approved booking holds the slot, else 'pending' for a rival request. */
    private function clash(Booking $booking): ?string
    {
        $overlaps = Booking::query()
            ->blocking($booking->resource_id, $booking->starts_at, $booking->ends_at)
            ->whereKeyNot($booking->id);

        if ((clone $overlaps)->where('status', BookingStatus::Approved->value)->exists()) {
            return 'approved';
        }

        return $overlaps->exists() ? 'pending' : null;
    }
}
