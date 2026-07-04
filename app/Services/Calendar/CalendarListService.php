<?php

namespace App\Services\Calendar;

use App\Http\Resources\CalendarResource;
use App\Models\Calendar;
use App\Models\CalendarUserPreference;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Builds the signed-in user's calendar list with preferences applied.
 * Shared by the list endpoint and each page's inlined first paint.
 */
class CalendarListService
{
    public function __construct(
        private readonly CalendarAccessService $access,
    ) {}

    /**
     * @param  list<string>|null  $types
     * @return list<array<string, mixed>>
     */
    public function forUser(
        Request $request,
        User $user,
        ?array $types = null,
        ?int $resourceTypeId = null,
    ): array {
        $calendars = $this->access
            ->scoped($this->access->accessibleCalendars($user), $types, $resourceTypeId)
            ->loadMissing('division');

        $preferences = CalendarUserPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('calendar_id', $calendars->pluck('id'))
            ->get()
            ->keyBy('calendar_id');

        return array_values($calendars
            ->map(function (Calendar $calendar) use ($request, $user, $preferences, $resourceTypeId): array {
                $item = CalendarResource::make($calendar)->forUser(
                    $user,
                    $preferences->get($calendar->id),
                    $this->access->canWriteEvents($user, $calendar),
                    $this->access->canManage($user, $calendar),
                )->resolve($request);

                // Resource calendars are hidden by default on the merged
                // dashboard, but a section page scoped to their resource type
                // should start with every room/vehicle visible.
                if ($resourceTypeId !== null) {
                    $item['is_visible'] = $preferences->get($calendar->id)->is_visible ?? true;
                }

                return $item;
            })
            ->all());
    }
}
