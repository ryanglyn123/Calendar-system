<?php

namespace App\Services\Google;

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\GoogleCalendar;
use App\Services\Calendar\CalendarAccessService;

/**
 * The app-side calendar row a synced Google calendar is shown through.
 * Owned by the connecting user and visible to nobody else
 * (CalendarAccessService), read-only for everyone.
 */
class GoogleMirror
{
    public const DEFAULT_COLOR = '#039BE5';

    public function __construct(private readonly CalendarAccessService $access) {}

    public function ensure(GoogleCalendar $calendar): Calendar
    {
        $calendar->loadMissing('calendar', 'account');
        $mirror = $calendar->calendar;
        $attributes = [
            'name' => $calendar->summary,
            'color' => $this->color($calendar->background_color),
        ];

        if ($mirror !== null) {
            $mirror->fill($attributes);

            if ($mirror->isDirty()) {
                $mirror->save();
            }

            return $mirror;
        }

        $mirror = Calendar::create($attributes + [
            'type' => CalendarType::Google,
            'owner_id' => $calendar->account->user_id,
            'created_by' => $calendar->account->user_id,
            'is_active' => true,
            'is_default' => false,
        ]);

        $calendar->forceFill(['calendar_id' => $mirror->id])->save();
        $calendar->setRelation('calendar', $mirror);
        $this->access->forgetFor($calendar->account->user);

        return $mirror;
    }

    /** Drop the mirrored events (access lost, or a full resync is starting over). */
    public function clearEvents(GoogleCalendar $calendar): void
    {
        if ($calendar->calendar_id !== null) {
            // Series first would cascade anyway; force-delete keeps no trace.
            Event::withTrashed()->where('calendar_id', $calendar->calendar_id)->forceDelete();
        }
    }

    /** Stop showing a Google calendar: its mirror and events go entirely. */
    public function remove(GoogleCalendar $calendar): void
    {
        $calendar->loadMissing('account.user');
        $this->clearEvents($calendar);

        if ($calendar->calendar_id !== null) {
            Calendar::withTrashed()->whereKey($calendar->calendar_id)->forceDelete();
            $calendar->forceFill(['calendar_id' => null])->save();
            $this->access->forgetFor($calendar->account->user);
        }

        $calendar->forceFill(['sync_token' => null, 'last_synced_at' => null])->save();
    }

    private function color(?string $hex): string
    {
        return is_string($hex) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtoupper($hex) : self::DEFAULT_COLOR;
    }
}
