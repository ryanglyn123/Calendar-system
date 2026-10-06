<?php

namespace App\Http\Controllers\Calendar;

use App\Enums\SharePermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\StoreCalendarShareRequest;
use App\Models\Calendar;
use App\Models\CalendarShare;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;

class CalendarShareController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendars,
    ) {}

    public function store(StoreCalendarShareRequest $request, Calendar $calendar): RedirectResponse
    {
        $user = User::query()->findOrFail((int) $request->validated('user_id'));

        $this->calendars->share(
            $calendar,
            $user,
            SharePermission::from($request->validated('permission')),
            $request->user(),
        );

        activity()
            ->causedBy($request->user())
            ->performedOn($calendar)
            ->event('shared')
            ->withProperties(['user_id' => $user->id, 'permission' => $request->validated('permission')])
            ->log("Shared calendar {$calendar->name} with {$user->name}");

        Toast::success("Calendar shared with {$user->name}.");

        return back();
    }

    public function destroy(Calendar $calendar, CalendarShare $share): RedirectResponse
    {
        $this->authorize('share', $calendar);

        abort_unless($calendar->isPersonal(), 403, 'Only personal calendars are shared person-to-person.');
        abort_unless($share->calendar_id === $calendar->id, 404);

        $this->calendars->revokeShare($share);

        activity()
            ->causedBy(request()->user())
            ->performedOn($calendar)
            ->event('share-revoked')
            ->withProperties(['user_id' => $share->user_id])
            ->log("Revoked a share on calendar {$calendar->name}");

        Toast::success('Share removed.');

        return back();
    }
}
