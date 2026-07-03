<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\UpdateCalendarPreferenceRequest;
use App\Models\Calendar;
use App\Services\Calendar\CalendarService;
use Illuminate\Http\JsonResponse;

class CalendarPreferenceController extends Controller
{
    /**
     * Upsert the signed-in user's visibility/color/order preference for one
     * calendar. Purely personal — never touches the calendar itself.
     */
    public function update(UpdateCalendarPreferenceRequest $request, Calendar $calendar, CalendarService $calendars): JsonResponse
    {
        $preference = $calendars->setUserPreference(
            $request->user(),
            $calendar,
            $request->validated(),
        );

        return response()->json([
            'is_visible' => $preference->is_visible,
            'color_override' => $preference->color_override,
            'sort_order' => $preference->sort_order,
        ]);
    }
}
