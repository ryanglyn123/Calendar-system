<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesInitialWindow;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\Booking\BookingPresenter;
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
    ): Response {
        abort_unless($resourceType->is_active, 404);

        $user = $request->user();

        $resources = $resourceType->resources()
            ->active()
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
        ]);
    }
}
