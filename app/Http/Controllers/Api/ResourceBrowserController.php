<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
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
     * Busy ranges for one resource on one day, so the picker can show
     * whether the requested slot is free.
     */
    public function availability(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $dayStart = Carbon::parse($validated['date'], 'Asia/Manila')->startOfDay()->utc();
        $dayEnd = $dayStart->copy()->addDay();

        $busy = Booking::query()
            ->blocking($resource->id, $dayStart, $dayEnd)
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at', 'status']);

        return response()->json([
            'data' => $busy->map(fn (Booking $booking) => [
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status->value,
            ]),
        ]);
    }
}
