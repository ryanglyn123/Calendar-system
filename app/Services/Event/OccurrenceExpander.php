<?php

namespace App\Services\Event;

use App\Enums\EventStatus;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class OccurrenceExpander
{
    /**
     * Hard cap per series so a runaway rule can never flood a response.
     */
    public const MAX_OCCURRENCES_PER_EVENT = 500;

    /**
     * Expand window-root events into concrete occurrences inside [start, end).
     *
     * Plain events pass through as a single occurrence. Recurring series are
     * expanded rule-by-rule with their override rows merged in (moved
     * occurrences replace the generated slot; cancelled ones drop out).
     *
     * @param  Collection<int, Event>  $roots  window-root events, overrides eager-loaded for recurring ones
     * @return list<EventOccurrence>
     */
    public function expand(Collection $roots, CarbonInterface $windowStart, CarbonInterface $windowEnd): array
    {
        $occurrences = [];

        foreach ($roots as $event) {
            if ($event->isRecurring()) {
                array_push($occurrences, ...$this->expandSeries($event, $windowStart, $windowEnd));
            } else {
                $occurrences[] = EventOccurrence::fromEvent($event);
            }
        }

        usort($occurrences, fn (EventOccurrence $a, EventOccurrence $b) => $a->startsAt <=> $b->startsAt);

        return $occurrences;
    }

    /**
     * @return list<EventOccurrence>
     */
    private function expandSeries(Event $parent, CarbonInterface $windowStart, CarbonInterface $windowEnd): array
    {
        $duration = (int) $parent->starts_at->diffInSeconds($parent->ends_at);

        /** @var Collection<string, Event> $overrides keyed by original start (UTC ISO) */
        $overrides = $parent->overrides
            ->keyBy(fn (Event $override) => $override->original_starts_at?->avoidMutation()->utc()->toIso8601String() ?? '');

        $occurrences = [];

        foreach ($this->occurrenceStarts($parent, $windowStart, $windowEnd) as $start) {
            $key = $start->avoidMutation()->utc()->toIso8601String();

            $override = $overrides->get($key);

            if ($override !== null) {
                if ($override->status === EventStatus::Cancelled || $override->trashed()) {
                    continue;
                }

                $occurrences[] = new EventOccurrence(
                    $override,
                    $override->starts_at,
                    $override->ends_at,
                    $start,
                );

                continue;
            }

            $occurrences[] = new EventOccurrence(
                $parent,
                $start,
                $start->avoidMutation()->addSeconds($duration),
                $start,
            );
        }

        // Overrides moved INTO the window from an original slot outside it
        // would otherwise be lost.
        foreach ($parent->overrides as $override) {
            if ($override->status === EventStatus::Cancelled || $override->trashed()) {
                continue;
            }

            $originalInWindow = $override->original_starts_at !== null
                && $override->original_starts_at >= $windowStart
                && $override->original_starts_at < $windowEnd;

            $movedIntoWindow = $override->starts_at < $windowEnd && $override->ends_at > $windowStart;

            if (! $originalInWindow && $movedIntoWindow && $override->original_starts_at !== null) {
                $occurrences[] = new EventOccurrence(
                    $override,
                    $override->starts_at,
                    $override->ends_at,
                    $override->original_starts_at,
                );
            }
        }

        return $occurrences;
    }

    /**
     * Concrete start times the series produces inside the window.
     *
     * P3 note: full RFC-5545 expansion via php-rrule arrives with the
     * recurrence phase; until events can carry an rrule this yields only the
     * base start when it overlaps the window.
     *
     * @return list<CarbonInterface>
     */
    private function occurrenceStarts(Event $parent, CarbonInterface $windowStart, CarbonInterface $windowEnd): array
    {
        $duration = (int) $parent->starts_at->diffInSeconds($parent->ends_at);

        $overlaps = $parent->starts_at < $windowEnd
            && $parent->starts_at->avoidMutation()->addSeconds($duration) > $windowStart;

        return $overlaps ? [$parent->starts_at] : [];
    }
}
