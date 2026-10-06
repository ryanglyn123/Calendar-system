<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\UpdateOccurrenceRequest;
use App\Models\Event;
use App\Services\Event\EventService;
use App\Services\Event\RecurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventOccurrenceController extends Controller
{
    public function __construct(
        private readonly RecurrenceService $recurrence,
        private readonly EventService $events,
    ) {}

    /**
     * Edit one occurrence of a series with Google-style scopes:
     * this / this and following / all events.
     */
    public function update(UpdateOccurrenceRequest $request, Event $event): JsonResponse
    {
        abort_if($event->isGoogleMirror(), 403, 'Events from Google Calendar are read-only here. Change them in Google Calendar.');

        abort_if(! $event->isRecurring(), 422, 'Not a recurring event; use the plain event endpoint.');

        $validated = $request->validated();
        $scope = $validated['scope'];
        $originalStart = Carbon::parse($validated['original_starts_at'])->utc();

        $attributes = collect($validated)
            ->except(['scope', 'original_starts_at'])
            ->map(fn ($value, $key) => in_array($key, ['starts_at', 'ends_at'], true)
                ? Carbon::parse($value)->utc()
                : $value)
            ->all();

        match ($scope) {
            'this' => $this->recurrence->editThisOccurrence($event, $originalStart, $attributes),
            'following' => $this->recurrence->editThisAndFollowing($event, $originalStart, $attributes),
            default => $this->editAll($event, $originalStart, $attributes),
        };

        return response()->json(['message' => 'Occurrence updated.']);
    }

    /**
     * Delete one occurrence of a series with the same scopes.
     */
    public function destroy(Request $request, Event $event): JsonResponse
    {
        abort_if($event->isGoogleMirror(), 403, 'Events from Google Calendar are read-only here. Change them in Google Calendar.');

        $this->authorize('delete', $event);

        abort_if(! $event->isRecurring(), 422, 'Not a recurring event; use the plain event endpoint.');

        $validated = $request->validate([
            'original_starts_at' => ['required', 'date'],
            'scope' => ['required', 'in:this,following,all'],
        ]);

        $originalStart = Carbon::parse($validated['original_starts_at'])->utc();

        match ($validated['scope']) {
            'this' => $this->recurrence->cancelThisOccurrence($event, $originalStart),
            'following' => $this->recurrence->editThisAndFollowing($event, $originalStart, [], delete: true),
            default => $event->delete(),
        };

        return response()->json(status: 204);
    }

    /**
     * "All events": time edits shift the whole series by the delta between
     * the touched occurrence and its new start; other fields apply directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function editAll(Event $event, Carbon $originalStart, array $attributes): void
    {
        if (isset($attributes['starts_at'])) {
            /** @var Carbon $newStart */
            $newStart = $attributes['starts_at'];

            /** @var Carbon $newEnd */
            $newEnd = $attributes['ends_at']
                ?? $newStart->avoidMutation()->addSeconds(
                    (int) $event->starts_at->diffInSeconds($event->ends_at),
                );

            $deltaSeconds = (int) $originalStart->diffInSeconds($newStart, false);
            $duration = (int) $newStart->diffInSeconds($newEnd);

            $attributes['starts_at'] = $event->starts_at->avoidMutation()->addSeconds($deltaSeconds);
            $attributes['ends_at'] = $attributes['starts_at']->avoidMutation()->addSeconds($duration);

            // Overrides are keyed by the slot they replace; the slots moved,
            // so the keys move with them (ordered to dodge the unique index).
            $event->overrides()
                ->orderBy('original_starts_at', $deltaSeconds > 0 ? 'desc' : 'asc')
                ->get()
                ->each(fn (Event $override) => $override->update([
                    'original_starts_at' => $override->original_starts_at->avoidMutation()->addSeconds($deltaSeconds),
                    'starts_at' => $override->starts_at->avoidMutation()->addSeconds($deltaSeconds),
                    'ends_at' => $override->ends_at->avoidMutation()->addSeconds($deltaSeconds),
                ]));
        }

        $this->events->update($event, $attributes);
    }
}
