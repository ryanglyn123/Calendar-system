<?php

namespace App\Services\Admin;

use App\Enums\BookingStatus;
use App\Enums\EditRequestStatus;
use App\Enums\UserStatus;
use App\Http\Resources\ActivityResource;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\BookingEditRequest;
use App\Models\Invitation;
use App\Models\ResourceType;
use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Everything the admin desk shows, each part shaped for its panel. Callers
 * decide who may see what; the queue additionally scopes itself to the
 * decisions this user may actually take.
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
     * Decisions waiting on this user: booking requests, change requests and
     * sign-ups, each carrying what is needed to decide without leaving.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(User $user): array
    {
        $items = [];

        if ($user->can('bookings.approve')) {
            $items = $this->bookingRequests($user);

            $changes = BookingEditRequest::query()
                ->where('status', EditRequestStatus::Pending->value)
                ->with(['booking.resource.type', 'booking.resource.calendar:id,color', 'booking.event:id,title', 'requester:id,name'])
                ->orderBy('created_at')
                ->get()
                ->filter(fn (BookingEditRequest $change) => $user->can('approve', $change->booking));

            foreach ($changes as $change) {
                $booking = $change->booking;

                $items[] = [
                    'key' => "change-{$change->id}",
                    'kind' => 'change',
                    'id' => $change->id,
                    'booking_id' => $booking->id,
                    'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
                    ...$this->resourceFields($booking),
                    'requester' => $change->requester?->name,
                    'starts_at' => ($change->proposed_starts_at ?? $booking->starts_at)->toIso8601String(),
                    'ends_at' => ($change->proposed_ends_at ?? $booking->ends_at)->toIso8601String(),
                    'current' => [
                        'starts_at' => $booking->starts_at->toIso8601String(),
                        'ends_at' => $booking->ends_at->toIso8601String(),
                        'purpose' => $booking->purpose,
                    ],
                    'proposed_purpose' => $change->proposed_purpose,
                    'note' => $change->note,
                    'requested_at' => $change->created_at?->toIso8601String(),
                ];
            }
        }

        if ($user->can('viewAny', User::class)) {
            $signUps = User::query()->pending()->with('division:id,name,acronym')->orderBy('created_at')->get();

            foreach ($signUps as $signUp) {
                $items[] = [
                    'key' => "registration-{$signUp->id}",
                    'kind' => 'registration',
                    'id' => $signUp->id,
                    'title' => $signUp->name,
                    'email' => $signUp->email,
                    'division' => $signUp->division?->acronym ?? $signUp->division?->name,
                    'requested_at' => $signUp->created_at?->toIso8601String(),
                ];
            }
        }

        return $items;
    }

    /**
     * Every active room and vehicle with its pending and approved bookings
     * on one office day.
     *
     * @return array<string, mixed>
     */
    public function schedule(User $user, CarbonImmutable $day): array
    {
        $from = $day->startOfDay();
        $to = $from->addDay();

        $bookings = Booking::query()
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value])
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->with(['event:id,title', 'requester:id,name', 'resource.type'])
            ->orderBy('starts_at')
            ->get()
            ->groupBy('resource_id');

        $types = ResourceType::query()
            ->active()
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
                'resources' => $type->resources->map(fn ($resource) => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'location' => $resource->location,
                    'color' => $resource->calendar?->color,
                    'bookings' => ($bookings->get($resource->id) ?? collect())
                        ->map(fn (Booking $booking) => [
                            'id' => $booking->id,
                            'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
                            'requester' => $booking->requester?->name,
                            'starts_at' => $booking->starts_at->toIso8601String(),
                            'ends_at' => $booking->ends_at->toIso8601String(),
                            'status' => $booking->status->value,
                            'purpose' => $booking->purpose,
                            'attendee_count' => $booking->attendee_count,
                            'can_approve' => $booking->status === BookingStatus::Pending
                                && $user->can('approve', $booking),
                        ])->values(),
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Booked minutes per office day for the next two weeks, split by status,
     * so busy days stand out before they arrive.
     *
     * @return list<array{date: string, approved: int, pending: int, count: int}>
     */
    public function demand(int $days = 14): array
    {
        $first = CarbonImmutable::now(self::timezone())->startOfDay();
        $last = $first->addDays($days);

        $bookings = Booking::query()
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value])
            ->where('starts_at', '<', $last->utc())
            ->where('ends_at', '>', $first->utc())
            ->get(['starts_at', 'ends_at', 'status']);

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($first, $bookings) {
                $dayStart = $first->addDays($offset);
                $dayEnd = $dayStart->addDay();
                $totals = ['approved' => 0, 'pending' => 0, 'count' => 0];

                foreach ($bookings as $booking) {
                    $start = max($booking->starts_at->getTimestamp(), $dayStart->getTimestamp());
                    $end = min($booking->ends_at->getTimestamp(), $dayEnd->getTimestamp());

                    if ($end <= $start) {
                        continue;
                    }

                    $totals[$booking->status === BookingStatus::Approved ? 'approved' : 'pending'] += intdiv($end - $start, 60);
                    $totals['count']++;
                }

                return ['date' => $dayStart->toDateString(), ...$totals];
            })
            ->all();
    }

    /**
     * Account counts that point at work: people waiting, invitations going
     * stale, accounts missing a division.
     *
     * @return array<string, int>
     */
    public function people(): array
    {
        $openInvitations = Invitation::query()->whereNull('accepted_at')->where('expires_at', '>', now());

        return [
            'active' => User::query()->active()->count(),
            'pending' => User::query()->pending()->count(),
            'suspended' => User::query()->where('status', UserStatus::Suspended->value)->count(),
            'without_division' => User::query()->active()->whereNull('division_id')->count(),
            'invitations_open' => (clone $openInvitations)->count(),
            'invitations_expiring' => (clone $openInvitations)->where('expires_at', '<=', now()->addDays(3))->count(),
        ];
    }

    /**
     * What is showing on everyone's calendar right now, plus what is queued.
     *
     * @return array{live: list<array<string, mixed>>, scheduled: int}
     */
    public function announcements(): array
    {
        $live = Announcement::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with('division:id,name,acronym')
            ->orderByDesc('starts_at')
            ->limit(5)
            ->get();

        return [
            'live' => $live->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'level' => $announcement->level,
                'audience' => $announcement->division?->acronym ?? $announcement->division?->name,
                'ends_at' => $announcement->ends_at?->toIso8601String(),
            ])->values()->all(),
            'scheduled' => Announcement::query()
                ->where('is_active', true)
                ->where('starts_at', '>', now())
                ->count(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function activity(int $limit = 8): Collection
    {
        // People's actions only; seeders and system jobs would drown them.
        return Activity::query()
            ->whereNotNull('causer_id')
            ->with('causer')
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $activity) => (new ActivityResource($activity))->resolve());
    }

    /** @return array{failed: int, queued: int} */
    public function health(): array
    {
        return [
            'failed' => DB::table('failed_jobs')->count(),
            'queued' => DB::table('jobs')->count(),
        ];
    }

    /**
     * Pending booking requests this user may decide, soonest first, each with
     * the first booking it overlaps (an approved one blocks approval).
     *
     * @return list<array<string, mixed>>
     */
    public function bookingRequests(User $user): array
    {
        $bookings = Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->with(['resource.type', 'resource.calendar:id,color', 'requester:id,name', 'event:id,title'])
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

    /** @return array{resource: string, color: string|null, type: string} */
    private function resourceFields(Booking $booking): array
    {
        return [
            'resource' => $booking->resource->name,
            'color' => $booking->resource->calendar?->color,
            'type' => $booking->resource->type->name,
        ];
    }
}
