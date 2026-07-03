<?php

namespace App\Services\Calendar;

use App\Enums\CalendarType;
use App\Enums\SharePermission;
use App\Models\Calendar;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class CalendarAccessService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Every calendar the user may read events from. Complete user isolation:
     * other users' personal calendars are never part of this set.
     *
     * Only the ID list is cached (models don't survive cache serialization
     * safely); the models themselves are one indexed query away.
     *
     * @return Collection<int, Calendar>
     */
    public function accessibleCalendars(User $user): Collection
    {
        /** @var list<int> $ids */
        $ids = Cache::remember(
            $this->cacheKey($user),
            self::CACHE_TTL_SECONDS,
            fn () => array_values(
                array_map('intval', $this->query($user)->pluck('id')->all()),
            ),
        );

        return Calendar::query()
            ->whereIn('id', $ids)
            ->with('shares')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<int>
     */
    public function accessibleCalendarIds(User $user): array
    {
        return array_values(
            $this->accessibleCalendars($user)
                ->map(fn (Calendar $calendar) => $calendar->id)
                ->all(),
        );
    }

    public function canView(User $user, Calendar $calendar): bool
    {
        if (! $calendar->is_active && ! $this->canManage($user, $calendar)) {
            return false;
        }

        return match ($calendar->type) {
            CalendarType::Organization, CalendarType::Resource => true,
            CalendarType::Division => $calendar->division_id === $user->division_id
                || $user->can('calendars.manage')
                || $this->sharePermission($user, $calendar) !== null,
            CalendarType::Personal => $calendar->isOwnedBy($user)
                || $this->sharePermission($user, $calendar) !== null,
        };
    }

    /**
     * Whether the user may create/update/delete events on the calendar.
     * Resource calendars are excluded here on purpose — their events are
     * managed through the booking workflow.
     */
    public function canWriteEvents(User $user, Calendar $calendar): bool
    {
        if (! $calendar->is_active) {
            return false;
        }

        return match ($calendar->type) {
            CalendarType::Organization => $user->can('events.create-org'),
            CalendarType::Division => $calendar->division_id === $user->division_id
                || $user->can('calendars.manage'),
            CalendarType::Personal => $calendar->isOwnedBy($user)
                || $this->sharePermission($user, $calendar)?->atLeast(SharePermission::Editor) === true,
            CalendarType::Resource => false,
        };
    }

    /**
     * Whether the user may edit the calendar itself (rename, recolor, share, delete).
     */
    public function canManage(User $user, Calendar $calendar): bool
    {
        return match ($calendar->type) {
            CalendarType::Organization, CalendarType::Division, CalendarType::Resource => $user->can('calendars.manage'),
            CalendarType::Personal => $calendar->isOwnedBy($user)
                || $this->sharePermission($user, $calendar) === SharePermission::Manager,
        };
    }

    public function sharePermission(User $user, Calendar $calendar): ?SharePermission
    {
        return $calendar->shares
            ->firstWhere('user_id', $user->id)
            ?->permission;
    }

    public function forgetFor(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    /**
     * Structural calendar changes (org/division/resource calendars created,
     * archived, re-colored…) affect every user's accessible set.
     */
    public function forgetAll(): void
    {
        Cache::increment($this->versionKey());
    }

    /**
     * @return Builder<Calendar>
     */
    private function query(User $user): Builder
    {
        return Calendar::query()
            ->active()
            ->where(function (Builder $query) use ($user) {
                $query
                    ->whereIn('type', [CalendarType::Organization->value, CalendarType::Resource->value])
                    ->orWhere(fn (Builder $q) => $q
                        ->where('type', CalendarType::Division->value)
                        ->where('division_id', $user->division_id))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('type', CalendarType::Personal->value)
                        ->where('owner_id', $user->id))
                    ->orWhereHas('shares', fn ($q) => $q->where('user_id', $user->id));
            })
            ->with('shares')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    private function cacheKey(User $user): string
    {
        $version = Cache::rememberForever($this->versionKey(), fn () => 1);

        return "calendars:accessible:v{$version}:user:{$user->id}";
    }

    private function versionKey(): string
    {
        return 'calendars:accessible:version';
    }
}
