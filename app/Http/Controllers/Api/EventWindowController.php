<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Event\EventWindowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class EventWindowController extends Controller
{
    /**
     * Occurrences for a date window across the user's selected calendars.
     */
    public function __invoke(Request $request, EventWindowService $window): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'calendars' => ['sometimes', 'array'],
            'calendars.*' => ['integer'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', Rule::in(['organization', 'division', 'personal', 'resource'])],
            'resource_type_id' => ['sometimes', 'integer'],
        ]);

        $start = Carbon::parse($validated['start']);
        $end = Carbon::parse($validated['end']);

        abort_if($start->diffInDays($end) > 190, 422, 'Window too large: request at most ~6 months.');

        return response()->json([
            'data' => $window->occurrences(
                $request,
                $request->user(),
                $start,
                $end,
                $validated['calendars'] ?? null,
                $validated['types'] ?? null,
                isset($validated['resource_type_id']) ? (int) $validated['resource_type_id'] : null,
            ),
        ]);
    }
}
