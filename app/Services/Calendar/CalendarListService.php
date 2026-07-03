<?php

namespace App\Services\Calendar;

use App\Http\Resources\CalendarResource;
use App\Models\Calendar;
use App\Models\CalendarUserPreference;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Builds the signed-in user's calendar list with preferences applied.
 * Shared by the list endpoint and the dashboard's inlined first paint.
 */
class CalendarListService
{
    public function __construct(
        private readonly CalendarAccessService $access,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(Request $request, User $user): array
    {
        $calendars = $this->access->accessibleCalendars($user)->loadMissing('division');

        $preferences = CalendarUserPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('calendar_id', $calendars->pluck('id'))
            ->get()
            ->keyBy('calendar_id');

        return array_values($calendars
            ->map(fn (Calendar $calendar): array => CalendarResource::make($calendar)->forUser(
                $user,
                $preferences->get($calendar->id),
                $this->access->canWriteEvents($user, $calendar),
                $this->access->canManage($user, $calendar),
            )->resolve($request))
            ->all());
    }
}
