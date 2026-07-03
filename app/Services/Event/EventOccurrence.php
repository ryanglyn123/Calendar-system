<?php

namespace App\Services\Event;

use App\Models\Event;
use Carbon\CarbonInterface;

/**
 * One concrete occurrence of an event inside a query window. Plain events
 * yield exactly one; recurring series yield one per expanded instance.
 */
class EventOccurrence
{
    public function __construct(
        public readonly Event $event,
        public readonly CarbonInterface $startsAt,
        public readonly CarbonInterface $endsAt,
        public readonly CarbonInterface $originalStartsAt,
    ) {}

    /**
     * Stable identifier the frontend and the occurrence endpoints share:
     * "{event id}:{original start, ISO-8601 UTC}".
     */
    public function id(): string
    {
        return "{$this->event->id}:{$this->originalStartsAt->avoidMutation()->utc()->toIso8601String()}";
    }

    public static function fromEvent(Event $event): self
    {
        return new self(
            $event,
            $event->starts_at,
            $event->ends_at,
            $event->original_starts_at ?? $event->starts_at,
        );
    }
}
