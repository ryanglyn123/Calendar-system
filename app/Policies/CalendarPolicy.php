<?php

namespace App\Policies;

use App\Models\Calendar;
use App\Models\User;
use App\Services\Calendar\CalendarAccessService;

class CalendarPolicy
{
    public function __construct(
        private readonly CalendarAccessService $access,
    ) {}

    public function view(User $user, Calendar $calendar): bool
    {
        return $this->access->canView($user, $calendar);
    }

    public function update(User $user, Calendar $calendar): bool
    {
        return $this->access->canManage($user, $calendar);
    }

    public function delete(User $user, Calendar $calendar): bool
    {
        return $this->access->canManage($user, $calendar);
    }

    public function share(User $user, Calendar $calendar): bool
    {
        // Only personal calendars are shared person-to-person; managed
        // calendars get their audience from type + division.
        return $calendar->isPersonal() && $this->access->canManage($user, $calendar);
    }
}
