<?php

namespace App\Services\Event;

use App\Enums\EventStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RRule\RRule;

class RecurrenceService
{
    /**
     * The end of the last occurrence a finite rule produces, used for SQL
     * windowing. Infinite rules yield null.
     */
    public function computeRecurrenceUntil(Event $event): ?CarbonInterface
    {
        if ($event->rrule === null) {
            return null;
        }

        $rule = $this->rule($event);

        if ($rule->isInfinite()) {
            return null;
        }

        $occurrences = $rule->getOccurrences(OccurrenceExpander::MAX_OCCURRENCES_PER_EVENT);

        if ($occurrences === []) {
            return $event->ends_at;
        }

        $duration = (int) $event->starts_at->diffInSeconds($event->ends_at);

        return CarbonImmutable::instance(end($occurrences))
            ->utc()
            ->addSeconds($duration);
    }

    /**
     * Occurrence start times inside [windowStart, windowEnd), in UTC.
     * Expansion runs in the event's own timezone so "every Monday 09:00"
     * stays 09:00 local across DST boundaries.
     *
     * @return list<CarbonInterface>
     */
    public function occurrenceStartsBetween(
        Event $event,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): array {
        $duration = (int) $event->starts_at->diffInSeconds($event->ends_at);

        $occurrences = $this->rule($event)->getOccurrencesBetween(
            $windowStart->avoidMutation()->subSeconds($duration)->toDateTime(),
            $windowEnd->toDateTime(),
            OccurrenceExpander::MAX_OCCURRENCES_PER_EVENT,
        );

        return array_values(array_map(
            fn (\DateTimeInterface $occurrence) => CarbonImmutable::instance($occurrence)->utc(),
            $occurrences,
        ));
    }

    /**
     * Apply an edit to a single occurrence: an override row shadows the
     * generated slot (Google's "this event" scope).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function editThisOccurrence(Event $parent, CarbonInterface $originalStart, array $attributes): Event
    {
        $duration = (int) $parent->starts_at->diffInSeconds($parent->ends_at);

        return Event::query()->updateOrCreate(
            [
                'recurring_parent_id' => $parent->id,
                'original_starts_at' => $originalStart->avoidMutation()->utc(),
            ],
            [
                'calendar_id' => $parent->calendar_id,
                'event_type_id' => $parent->event_type_id,
                'created_by' => $parent->created_by,
                'title' => $parent->title,
                'description' => $parent->description,
                'location' => $parent->location,
                'meeting_url' => $parent->meeting_url,
                'all_day' => $parent->all_day,
                'timezone' => $parent->timezone,
                'color' => $parent->color,
                'visibility' => $parent->visibility,
                'starts_at' => $originalStart->avoidMutation()->utc(),
                'ends_at' => $originalStart->avoidMutation()->utc()->addSeconds($duration),
                ...$attributes,
            ],
        );
    }

    /**
     * Cancel one occurrence: a cancelled override swallows the slot.
     */
    public function cancelThisOccurrence(Event $parent, CarbonInterface $originalStart): Event
    {
        return $this->editThisOccurrence($parent, $originalStart, [
            'status' => EventStatus::Cancelled,
        ]);
    }

    /**
     * "This and following": truncate the parent series just before the
     * occurrence, then start a fresh series from the (possibly edited)
     * occurrence onward. Returns the new series parent, or null when the
     * edit simply deleted the tail.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function editThisAndFollowing(
        Event $parent,
        CarbonInterface $originalStart,
        array $attributes,
        bool $delete = false,
    ): ?Event {
        return DB::transaction(function () use ($parent, $originalStart, $attributes, $delete) {
            $originalStartUtc = $originalStart->avoidMutation()->utc();

            // Occurrences from the split point on belong to the new series;
            // their overrides on the old parent must not survive.
            $parent->overrides()
                ->where('original_starts_at', '>=', $originalStartUtc)
                ->forceDelete();

            $isFirstOccurrence = $parent->starts_at->equalTo($originalStartUtc);

            if ($isFirstOccurrence) {
                // Splitting at the very first occurrence rewrites the whole series.
                if ($delete) {
                    $parent->delete();

                    return null;
                }

                $parent->update($attributes);
                $parent->update([
                    'recurrence_until' => $this->computeRecurrenceUntil($parent->refresh()),
                ]);

                return $parent;
            }

            // Capture the tail's rule BEFORE truncation rewrites the parent's.
            $tailRule = $this->remainingRule($parent, $originalStartUtc);

            $this->truncateBefore($parent, $originalStartUtc);

            if ($delete) {
                return null;
            }

            $duration = (int) $parent->starts_at->diffInSeconds($parent->ends_at);

            $newParent = Event::query()->create([
                'calendar_id' => $parent->calendar_id,
                'event_type_id' => $parent->event_type_id,
                'created_by' => $parent->created_by,
                'title' => $parent->title,
                'description' => $parent->description,
                'location' => $parent->location,
                'meeting_url' => $parent->meeting_url,
                'all_day' => $parent->all_day,
                'timezone' => $parent->timezone,
                'color' => $parent->color,
                'visibility' => $parent->visibility,
                'status' => $parent->status,
                'rrule' => $tailRule,
                'starts_at' => $originalStartUtc,
                'ends_at' => $originalStartUtc->avoidMutation()->addSeconds($duration),
                ...$attributes,
            ]);

            $newParent->update([
                'recurrence_until' => $this->computeRecurrenceUntil($newParent->refresh()),
            ]);

            return $newParent;
        });
    }

    /**
     * Truncate a series so it ends before the given occurrence.
     */
    public function truncateBefore(Event $parent, CarbonInterface $beforeUtc): void
    {
        $parts = $this->ruleParts($parent);

        unset($parts['COUNT']);

        // UNTIL is compared against occurrence starts in the rule's timezone.
        $parts['UNTIL'] = $beforeUtc->avoidMutation()->subSecond()->utc()->format('Ymd\THis\Z');

        $parent->update(['rrule' => $this->partsToString($parts)]);
        $parent->update([
            'recurrence_until' => $this->computeRecurrenceUntil($parent->refresh()),
        ]);
    }

    /**
     * The rule the tail series keeps after a split: same pattern, with a
     * COUNT reduced by the occurrences the original series already used.
     */
    private function remainingRule(Event $parent, CarbonInterface $splitUtc): string
    {
        $parts = $this->ruleParts($parent);

        if (isset($parts['COUNT'])) {
            $used = count($this->rule($parent)->getOccurrencesBetween(
                null,
                $splitUtc->avoidMutation()->subSecond()->toDateTime(),
                OccurrenceExpander::MAX_OCCURRENCES_PER_EVENT,
            ));

            $parts['COUNT'] = (string) max(1, (int) $parts['COUNT'] - $used);
        }

        return $this->partsToString($parts);
    }

    /**
     * @return RRule<int, \DateTime>
     */
    private function rule(Event $event): RRule
    {
        // Note: this reads the CURRENT rrule; call before mutating it.
        $dtstart = $event->starts_at->avoidMutation()->setTimezone($event->timezone);

        return new RRule((string) $event->rrule, $dtstart->toDateTime());
    }

    /**
     * @return array<string, string>
     */
    private function ruleParts(Event $event): array
    {
        $parts = [];

        foreach (explode(';', (string) $event->rrule) as $chunk) {
            if (str_contains($chunk, '=')) {
                [$key, $value] = explode('=', $chunk, 2);
                $parts[strtoupper(trim($key))] = trim($value);
            }
        }

        return $parts;
    }

    /**
     * @param  array<string, string>  $parts
     */
    private function partsToString(array $parts): string
    {
        return implode(';', array_map(
            fn (string $key, string $value) => "{$key}={$value}",
            array_keys($parts),
            $parts,
        ));
    }
}
