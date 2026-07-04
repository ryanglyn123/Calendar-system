<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Calendar\CalendarListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CalendarListController extends Controller
{
    /**
     * The signed-in user's calendar list with their preferences applied —
     * the single source the sidebar and event grid work from. Scoped pages
     * (public calendar, resource sections) narrow it with the same filters
     * their inlined first paint used.
     */
    public function __invoke(Request $request, CalendarListService $list): JsonResponse
    {
        $validated = $request->validate([
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', Rule::in(['organization', 'division', 'personal', 'resource'])],
            'resource_type_id' => ['sometimes', 'integer'],
        ]);

        return response()->json([
            'data' => $list->forUser(
                $request,
                $request->user(),
                $validated['types'] ?? null,
                isset($validated['resource_type_id']) ? (int) $validated['resource_type_id'] : null,
            ),
        ]);
    }
}
