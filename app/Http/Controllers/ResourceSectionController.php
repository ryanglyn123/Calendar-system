<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesInitialWindow;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\Booking\BookingPresenter;
use App\Services\Booking\ReviewQueue;
use App\Services\Calendar\CalendarListService;
use App\Services\Event\EventWindowService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A resource type's own section: its calendar (one sub-calendar per room or
 * vehicle) plus booking on the same page. The sidebar links one section per
 * active resource type (Boardroom, Carpool, ...).
 */
class ResourceSectionController extends Controller
{
    use ResolvesInitialWindow;

    public function __invoke(
        Request $request,
        ResourceType $resourceType,
        GeneralSettings $settings,
        CalendarListService $list,
        EventWindowService $window,
        BookingPresenter $presenter,
        ReviewQueue $queue,
    ): Response {
        abort_unless($resourceType->is_active, 404);

        $user = $request->user();

        $resources = $resourceType->resources()
            ->active()
            ->with('calendar:id,color')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('ResourceSection', [
            'resourceType' => [
                'id' => $resourceType->id,
                'name' => $resourceType->name,
                'slug' => $resourceType->slug,
                'icon' => $resourceType->icon,
                'description' => $resourceType->description,
                'requires_approval' => $resourceType->requires_approval,
                'max_duration_minutes' => $resourceType->max_duration_minutes,
            ],
            'resources' => $resources->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'name' => $resource->name,
                'location' => $resource->location,
                'capacity' => $resource->capacity,
                'description' => $resource->description,
                'calendar_id' => $resource->calendar_id,
                // The room's calendar color, so the booking picker matches the grid.
                'color' => $resource->calendar?->color,
            ])->values(),
            'weekStartsOn' => $settings->week_starts_on,
            'initialCalendars' => fn () => $list->forUser(
                $request,
                $user,
                resourceTypeId: $resourceType->id,
            ),
            'initialEvents' => fn () => $window->occurrences(
                $request,
                $user,
                ...$this->initialSpan($user->timezone, $settings->week_starts_on),
                resourceTypeId: $resourceType->id,
            ),
            // The user's bookings ride along too, so the section paints in a
            // single request instead of a follow-up API call.
            'initialBookings' => fn () => Booking::query()
                ->where('requested_by', $user->id)
                ->whereHas('resource', fn ($q) => $q->where('resource_type_id', $resourceType->id))
                ->with(['resource.type', 'editRequests'])
                ->orderByDesc('starts_at')
                ->limit(100)
                ->get()
                ->map(fn (Booking $booking): array => $presenter->toArray($booking))
                ->values()
                ->all(),
            // Reviewers of this type work from the calendar: what waits on
            // them, to pick from without leaving the page.
            'review' => fn () => $this->reviewSummary($user, $resourceType, $queue),
            // The view this browser last chose for this type and role, so the
            // server renders the calendar the browser will show.
            'rememberedView' => $this->rememberedView(
                $request,
                $resourceType->slug.':'.($this->manages($user, $resourceType) ? 'manage' : 'book'),
            ),
        ]);
    }

    /** Approvers of this type (and super admins) manage it; others book from it. */
    private function manages(User $user, ResourceType $resourceType): bool
    {
        return $user->hasRole('super_admin')
            || ($user->can('bookings.approve') && $user->roles->contains('id', $resourceType->approver_role_id));
    }

    /**
     * The `booking_views` cookie maps "slug:role" to a view. It is set by
     * the page itself, so anything unexpected in it is ignored.
     */
    private function rememberedView(Request $request, string $context): ?string
    {
        $views = json_decode((string) $request->cookie('booking_views'), true);
        $view = is_array($views) ? ($views[$context] ?? null) : null;

        return in_array($view, ['day', 'week', 'month'], true) ? $view : null;
    }

    /**
     * Reviewers of this type get a way into the review queue: the count of
     * waiting requests (past ones too: they still need a decision), the
     * soonest of them to pick from on the calendar and, when exactly one
     * waits, that request, so it can be opened directly.
     *
     * @return array{count: int, booking: int|null, items: list<array<string, mixed>>}|null
     */
    private function reviewSummary(User $user, ResourceType $resourceType, ReviewQueue $queue): ?array
    {
        if (! $this->manages($user, $resourceType)) {
            return null;
        }

        $waiting = $queue->forType($resourceType);

        return $waiting + [
            'booking' => $waiting['count'] === 1 ? $waiting['items'][0]['id'] : null,
        ];
    }
}
