<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\User;
use App\Notifications\EventInvitedNotification;
use App\Settings\GeneralSettings;
use Illuminate\Support\Carbon;

class EventService
{
    public function __construct(
        private readonly RecurrenceService $recurrence,
    ) {}

    /**
     * Expects FormRequest-validated attributes. Datetimes are normalized to
     * UTC before storage (Eloquent's cast keeps wall time as-is, so tz-aware
     * input must be converted here). All-day events are stored end-exclusive
     * and anchored to the creator's local midnight: starts_at 00:00 on the
     * first day, ends_at 00:00 the day after the last day.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $creator, array $attributes): Event
    {
        $timezone = $creator->timezone ?: app(GeneralSettings::class)->timezone;

        $event = Event::query()->create([
            ...$this->normalize($attributes, $timezone),
            'created_by' => $creator->id,
            'timezone' => $timezone,
        ]);

        $this->syncRecurrenceUntil($event);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Event $event, array $attributes): Event
    {
        // Overrides belong to a series; the series carries the rule.
        if ($event->isOverride()) {
            unset($attributes['rrule']);
        }

        $event->update($this->normalize($attributes, $event->timezone, $event));

        $this->syncRecurrenceUntil($event);

        return $event;
    }

    /**
     * Sync the invited users. Newly invited people are notified; the creator
     * is kept as organizer implicitly.
     *
     * @param  list<int>  $userIds
     */
    public function syncAttendees(Event $event, array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(
            $userIds,
            fn (int $id) => $id !== $event->created_by,
        )));

        $existing = $event->attendees()->pluck('user_id')->all();

        $event->attendees()
            ->whereNotIn('user_id', $userIds)
            ->where('is_organizer', false)
            ->delete();

        $added = array_diff($userIds, $existing);

        foreach ($added as $userId) {
            $event->attendees()->create(['user_id' => $userId]);
        }

        if ($added !== []) {
            $users = User::query()->active()->whereIn('id', $added)->get();

            foreach ($users as $user) {
                $user->notify(new EventInvitedNotification($event));
            }
        }
    }

    /**
     * Replace the given user's reminders on the event. Each selected lead
     * time delivers on both channels; the per-key preference still filters.
     *
     * @param  list<int>  $minutes
     */
    public function syncReminders(Event $event, User $user, array $minutes): void
    {
        $minutes = array_values(array_unique(array_map('intval', $minutes)));

        $event->reminders()->where('user_id', $user->id)->delete();

        foreach ($minutes as $lead) {
            foreach (['database', 'mail'] as $channel) {
                $event->reminders()->create([
                    'user_id' => $user->id,
                    'minutes_before' => $lead,
                    'channel' => $channel,
                ]);
            }
        }
    }

    private function syncRecurrenceUntil(Event $event): void
    {
        $until = $this->recurrence->computeRecurrenceUntil($event);

        if (($until?->toIso8601String()) !== ($event->recurrence_until?->toIso8601String())) {
            $event->update(['recurrence_until' => $until]);
        }
    }

    public function delete(Event $event): void
    {
        $event->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes, string $timezone, ?Event $existing = null): array
    {
        $allDay = (bool) ($attributes['all_day'] ?? $existing->all_day ?? false);

        if ($allDay && isset($attributes['starts_at'], $attributes['ends_at'])) {
            $start = Carbon::parse($attributes['starts_at'], $timezone)
                ->setTimezone($timezone)
                ->startOfDay();
            $end = Carbon::parse($attributes['ends_at'], $timezone)
                ->setTimezone($timezone)
                ->startOfDay();

            if ($end <= $start) {
                $end = $start->clone();
            }

            $attributes['starts_at'] = $start->utc();
            $attributes['ends_at'] = $end->addDay()->utc();

            return $attributes;
        }

        foreach (['starts_at', 'ends_at'] as $key) {
            if (isset($attributes[$key])) {
                $attributes[$key] = Carbon::parse($attributes[$key], $timezone)->utc();
            }
        }

        return $attributes;
    }
}
