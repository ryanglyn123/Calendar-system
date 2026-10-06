<?php

namespace App\Services\Calendar;

use App\Enums\CalendarType;
use App\Enums\SharePermission;
use App\Models\Calendar;
use App\Models\CalendarShare;
use App\Models\CalendarUserPreference;
use App\Models\Division;
use App\Models\User;
use InvalidArgumentException;

class CalendarService
{
    public function __construct(
        private readonly CalendarAccessService $access,
    ) {}

    /**
     * Expects FormRequest-validated attributes (name, description, color, icon).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createPersonalCalendar(User $owner, array $attributes): Calendar
    {
        $calendar = Calendar::query()->create([
            ...$attributes,
            'type' => CalendarType::Personal,
            'owner_id' => $owner->id,
            'division_id' => null,
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        $this->access->forgetFor($owner);

        return $calendar;
    }

    /**
     * The user's own calendar (Google's "My calendars" entry): one per user,
     * named after them, created the first time their calendar list is built.
     */
    public function ensurePrimaryCalendar(User $owner): Calendar
    {
        $calendar = Calendar::query()->firstOrCreate(
            [
                'type' => CalendarType::Personal->value,
                'owner_id' => $owner->id,
                'is_default' => true,
            ],
            [
                'name' => $owner->name,
                'color' => '#039BE5',
                'division_id' => null,
                'created_by' => $owner->id,
            ],
        );

        if ($calendar->wasRecentlyCreated) {
            $this->access->forgetFor($owner);
        }

        return $calendar;
    }

    /**
     * Expects FormRequest-validated attributes (name, description, color,
     * icon, division_id, is_default, is_active, sort_order).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createManagedCalendar(CalendarType $type, array $attributes, User $createdBy): Calendar
    {
        if ($type === CalendarType::Personal) {
            throw new InvalidArgumentException('Personal calendars are created through createPersonalCalendar().');
        }

        if ($type === CalendarType::Division && blank($attributes['division_id'] ?? null)) {
            throw new InvalidArgumentException('Division calendars require a division.');
        }

        if ($type !== CalendarType::Division) {
            $attributes['division_id'] = null;
        }

        $calendar = Calendar::query()->create([
            ...$attributes,
            'type' => $type,
            'owner_id' => null,
            'created_by' => $createdBy->id,
        ]);

        $this->access->forgetAll();

        return $calendar;
    }

    public function createDivisionCalendar(Division $division): Calendar
    {
        $calendar = Calendar::query()->firstOrCreate(
            [
                'type' => CalendarType::Division->value,
                'division_id' => $division->id,
            ],
            [
                'name' => $division->name,
                'description' => "Shared calendar for {$division->name}.",
                'color' => $division->color,
                // The division's own calendar; unit calendars are is_default=false.
                'is_default' => true,
                'is_active' => $division->is_active,
            ],
        );

        $this->access->forgetAll();

        return $calendar;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Calendar $calendar, array $attributes): Calendar
    {
        $calendar->update($attributes);

        $calendar->isPersonal() && $calendar->owner !== null
            ? $this->access->forgetFor($calendar->owner)
            : $this->access->forgetAll();

        return $calendar;
    }

    public function delete(Calendar $calendar): void
    {
        $owner = $calendar->owner;

        $calendar->delete();

        $calendar->isPersonal() && $owner !== null
            ? $this->access->forgetFor($owner)
            : $this->access->forgetAll();
    }

    public function share(Calendar $calendar, User $with, SharePermission $permission, User $sharedBy): CalendarShare
    {
        $share = CalendarShare::query()->updateOrCreate(
            ['calendar_id' => $calendar->id, 'user_id' => $with->id],
            ['permission' => $permission, 'created_by' => $sharedBy->id],
        );

        $this->access->forgetFor($with);

        return $share;
    }

    public function revokeShare(CalendarShare $share): void
    {
        $user = $share->user;

        $share->delete();

        if ($user !== null) {
            $this->access->forgetFor($user);
        }
    }

    /**
     * Upsert a per-user preference (visibility, color override, ordering).
     *
     * @param  array{is_visible?: bool|null, color_override?: string|null, sort_order?: int|null}  $attributes
     */
    public function setUserPreference(User $user, Calendar $calendar, array $attributes): CalendarUserPreference
    {
        $preference = CalendarUserPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'calendar_id' => $calendar->id],
            $attributes,
        );

        return $preference;
    }
}
