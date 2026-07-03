<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;

class EventService
{
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
        $timezone = $creator->timezone ?: 'Asia/Manila';

        return Event::query()->create([
            ...$this->normalize($attributes, $timezone),
            'created_by' => $creator->id,
            'timezone' => $timezone,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Event $event, array $attributes): Event
    {
        $event->update($this->normalize($attributes, $event->timezone, $event));

        return $event;
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
