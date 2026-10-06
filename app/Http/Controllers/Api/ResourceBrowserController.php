<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Holiday;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\WorkingHour;
use App\Settings\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ResourceBrowserController extends Controller
{
    /**
     * Active resource types with their bookable resources, for the picker.
     */
    public function index(): JsonResponse
    {
        $types = ResourceType::query()
            ->active()
            ->with(['resources' => fn ($query) => $query->active()->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $types->map(fn (ResourceType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'icon' => $type->icon,
                'requires_approval' => $type->requires_approval,
                'max_duration_minutes' => $type->max_duration_minutes,
                'resources' => $type->resources->map(fn (Resource $resource): array => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'location' => $resource->location,
                    'capacity' => $resource->capacity,
                    'description' => $resource->description,
                ])->all(),
            ]),
        ]);
    }

    /**
     * Busy ranges for one resource on one day, plus the day's opening hours
     * and any blocking holiday, so the picker can show what is bookable
     * before the request is sent. Mirrors BookingRuleValidator.
     */
    public function availability(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $day = Carbon::parse($validated['date'], app(GeneralSettings::class)->timezone)->startOfDay();
        $dayStart = $day->copy()->utc();
        $dayEnd = $dayStart->copy()->addDay();

        $busy = Booking::query()
            ->blocking($resource->id, $dayStart, $dayEnd)
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at', 'status']);

        $type = $resource->type;
        $schedule = $type->enforce_working_hours
            ? WorkingHour::for($resource->id, $day->dayOfWeek)
            : null;
        $closed = $type->enforce_working_hours && ($schedule === null || $schedule->isClosed());

        return response()->json([
            'data' => $busy->map(fn (Booking $booking) => [
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status->value,
            ]),
            // null = no opening-hours limit for this type.
            'hours' => $schedule && ! $closed ? [
                'opens' => substr((string) $schedule->opens_at, 0, 5),
                'closes' => substr((string) $schedule->closes_at, 0, 5),
            ] : null,
            'closed' => $closed,
            'holiday' => $type->block_holidays ? Holiday::blockingFor($day)?->name : null,
        ]);
    }
}
