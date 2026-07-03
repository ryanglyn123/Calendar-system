<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\CalendarAccessService;

class EventPolicy
{
    public function __construct(
        private readonly CalendarAccessService $access,
    ) {}

    public function view(User $user, Event $event): bool
    {
        return $this->access->canView($user, $event->calendar);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->access->canWriteEvents($user, $event->calendar);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->access->canWriteEvents($user, $event->calendar);
    }
}
