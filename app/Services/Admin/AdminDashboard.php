<?php

namespace App\Services\Admin;

use App\Enums\BookingStatus;
use App\Enums\EditRequestStatus;
use App\Models\Booking;
use App\Models\BookingEditRequest;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a reviewer's desk shows for one resource type (Boardroom, Carpool):
 * the requests waiting on them and each room's or vehicle's bookings on a
 * day. Callers pick types from ReviewQueue::typesFor(), so nobody gets a
 * desk for a type they don't review.
 */
class AdminDashboard
{
    /** @deprecated Use timezone(): the office timezone is an admin setting. */
    public const TIMEZONE = 'Asia/Manila';

    /** Day boundaries follow the office, like the booking rules do. */
    public static function timezone(): string
    {
        return app(GeneralSettings::class)->timezone;
    }

    /**
     * The desk switcher: every type this person reviews and what waits there.
     *
     * @param  Collection<int, ResourceType>  $types
     * @return list<array{slug: string, name: string, icon: string|null, waiting: int}>
     */
    public function tabs(Collection $types): array
    {
        // ponytail: two count queries per type; there are two types.
        return array_values($types->map(fn (ResourceType $type) => [
            'slug' => $type->slug,
            'name' => $type->name,
            'icon' => $type->icon,
            'waiting' => Booking::query()
                ->where('status', BookingStatus::Pending->value)
                ->whereHas('resource', fn ($query) => $query->where('resource_type_id', $type->id))
                ->count()
                + BookingEditRequest::query()
                    ->where('status', EditRequestStatus::Pending->value)
                    ->whereHas('booking.resource', fn ($query) => $query->where('resource_type_id', $type->id))
                    ->count(),
        ])->all());
    }

    /**
     * Booking and change requests in one type that this user may decide.
     *
     * @return list<array<string, mixed>>
     */
    public function requests(User $user, ResourceType $type): array
    {
        return [...$this->bookingRequests($user, $type), ...$this->changeRequests($user, $type)];
    }

    /**
     * Pending booking requests this user may decide, soonest first, each with
     * the first booking it overlaps (an approved one blocks approval).
     *
     * @return list<array<string, mixed>>
     */
    public function bookingRequests(User $user, ?ResourceType $type = null): array
    {
        $bookings = Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->when($type, fn ($query) => $query->whereHas('resource', fn ($resource) => $resource->where('resource_type_id', $type->id)))
            ->with(['resource.type', 'resource.calendar:id,color', 'requester.division:id,name,acronym', 'event:id,title'])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Booking $booking) => $user->can('approve', $booking));

        $items = [];

        foreach ($bookings as $booking) {
            // ponytail: one clash query per pending booking; batch it if queues ever run long.
            $clash = Booking::query()
                ->blocking($booking->resource_id, $booking->starts_at, $booking->ends_at)
                ->whereKeyNot($booking->id)
                ->with('event:id,title')
                ->orderBy('starts_at')
                ->first();

            $items[] = [
                'key' => "booking-{$booking->id}",
                'kind' => 'booking',
                'id' => $booking->id,
                'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
                ...$this->resourceFields($booking),
                'requester' => $booking->requester?->name,
                'division' => $this->divisionOf($booking->requester),
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'purpose' => $booking->purpose,
                'attendee_count' => $booking->attendee_count,
                'capacity' => $booking->resource->capacity,
                'requested_at' => $booking->created_at?->toIso8601String(),
                'clash' => $clash === null ? null : [
                    'title' => $clash->event->title ?? $clash->purpose ?? 'Booking',
                    'status' => $clash->status->value,
                    'starts_at' => $clash->starts_at->toIso8601String(),
                    'ends_at' => $clash->ends_at->toIso8601String(),
                ],
            ];
        }

        return $items;
    }

    /**
     * Pending change requests in one type that this user may decide, oldest
     * first, with the booking as it stands so the change reads as a diff.
     *
     * @return list<array<string, mixed>>
     */
    public function changeRequests(User $user, ResourceType $type): array
    {
        return array_values(BookingEditRequest::query()
            ->where('status', EditRequestStatus::Pending->value)
            ->whereHas('booking.resource', fn ($query) => $query->where('resource_type_id', $type->id))
            ->with(['booking.resource.type', 'booking.resource.calendar:id,color', 'booking.event:id,title', 'requester.division:id,name,acronym'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (BookingEditRequest $change) => $user->can('approve', $change->booking))
            ->map(function (BookingEditRequest $change) {
                $booking = $change->booking;

                return [
                    'key' => "change-{$change->id}",
                    'kind' => 'change',
                    'id' => $change->id,
                    'booking_id' => $booking->id,
                    'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
                    ...$this->resourceFields($booking),
                    'requester' => $change->requester?->name,
                    'division' => $this->divisionOf($change->requester),
                    'starts_at' => ($change->proposed_starts_at ?? $booking->starts_at)->toIso8601String(),
                    'ends_at' => ($change->proposed_ends_at ?? $booking->ends_at)->toIso8601String(),
                    'current' => [
                        'starts_at' => $booking->starts_at->toIso8601String(),
                        'ends_at' => $booking->ends_at->toIso8601String(),
                        'purpose' => $booking->purpose,
                        'attendee_count' => $booking->attendee_count,
                    ],
                    'proposed_purpose' => $change->proposed_purpose,
                    'proposed_attendee_count' => $change->proposed_attendee_count,
                    'note' => $change->note,
                    'requested_at' => $change->created_at?->toIso8601String(),
                ];
            })
            ->all());
    }

    /**
     * Every active room and vehicle (of one type, when given) with its
     * pending and approved bookings on one office day.
     *
     * @return array<string, mixed>
     */
    public function schedule(User $user, CarbonImmutable $day, ?ResourceType $only = null): array
    {
        $from = $day->startOfDay();
        $to = $from->addDay();

        $bookings = Booking::query()
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value])
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->when($only, fn ($query) => $query->whereHas('resource', fn ($resource) => $resource->where('resource_type_id', $only->id)))
            ->with(['event:id,title', 'requester:id,name', 'resource.type'])
            ->orderBy('starts_at')
            ->get()
            ->groupBy('resource_id');

        $types = ResourceType::query()
            ->active()
            ->when($only, fn ($query) => $query->whereKey($only->id))
            ->with(['resources' => fn ($query) => $query->active()->with('calendar:id,color')->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'date' => $from->toDateString(),
            'types' => $types->map(fn (ResourceType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
                'icon' => $type->icon,
                'resources' => $type->resources->map(fn (Resource $resource) => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'location' => $resource->location,
                    'color' => $resource->calendar?->color,
                    'bookings' => ($bookings->get($resource->id) ?? collect())
                        ->map(function (Booking $booking) use ($user) {
                            // Same rule as the calendar (EventOccurrenceResource):
                            // purpose and headcount only for the requester and
                            // the booking's own reviewers.
                            $canReview = $user->can('approve', $booking);
                            $full = $canReview || $booking->requested_by === $user->id;

                            return [
                                'id' => $booking->id,
                                'title' => $booking->event->title ?? ($full ? $booking->purpose : null) ?? 'Booking',
                                'requester' => $booking->requester?->name,
                                'starts_at' => $booking->starts_at->toIso8601String(),
                                'ends_at' => $booking->ends_at->toIso8601String(),
                                'status' => $booking->status->value,
                                'purpose' => $full ? $booking->purpose : null,
                                'attendee_count' => $full ? $booking->attendee_count : null,
                                'can_approve' => $booking->status === BookingStatus::Pending && $canReview,
                            ];
                        })->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Seven office days from `$from` with how many bookings each holds and
     * how many of those still wait for a decision.
     *
     * @return list<array{date: string, bookings: int, waiting: int}>
     */
    public function week(ResourceType $type, CarbonImmutable $from): array
    {
        $to = $from->addDays(7);

        $bookings = Booking::query()
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value])
            ->whereHas('resource', fn ($query) => $query->where('resource_type_id', $type->id))
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->get(['starts_at', 'ends_at', 'status']);

        return array_map(function (int $offset) use ($from, $bookings) {
            $start = $from->addDays($offset);
            $end = $start->addDay();
            $that = $bookings->filter(fn (Booking $booking) => $booking->starts_at < $end && $booking->ends_at > $start);

            return [
                'date' => $start->toDateString(),
                'bookings' => $that->count(),
                'waiting' => $that->where('status', BookingStatus::Pending)->count(),
            ];
        }, range(0, 6));
    }

    /** @return array{resource: string, color: string|null, type: string} */
    private function resourceFields(Booking $booking): array
    {
        return [
            'resource' => $booking->resource->name,
            'color' => $booking->resource->calendar?->color,
            'type' => $booking->resource->type->name,
        ];
    }

    private function divisionOf(?User $user): ?string
    {
        return $user->division->acronym ?? $user->division->name ?? null;
    }
}
