<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarResource;
use App\Models\CalendarUserPreference;
use App\Services\Calendar\CalendarAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CalendarListController extends Controller
{
    /**
     * The signed-in user's calendar list with their preferences applied —
     * the single source the sidebar and event grid work from.
     */
    public function __invoke(Request $request, CalendarAccessService $access): AnonymousResourceCollection
    {
        $user = $request->user();

        $calendars = $access->accessibleCalendars($user)->loadMissing('division');

        $preferences = CalendarUserPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('calendar_id', $calendars->pluck('id'))
            ->get()
            ->keyBy('calendar_id');

        return CalendarResource::collection(
            $calendars->map(fn ($calendar) => CalendarResource::make($calendar)->forUser(
                $user,
                $preferences->get($calendar->id),
                $access->canWriteEvents($user, $calendar),
                $access->canManage($user, $calendar),
            )),
        );
    }
}
