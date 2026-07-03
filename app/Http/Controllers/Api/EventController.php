<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Resources\EventOccurrenceResource;
use App\Models\CalendarUserPreference;
use App\Models\Event;
use App\Services\Calendar\CalendarAccessService;
use App\Services\Event\EventOccurrence;
use App\Services\Event\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(
        private readonly EventService $events,
        private readonly CalendarAccessService $access,
    ) {}

    public function store(StoreEventRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $event = $this->events->create(
            $request->user(),
            collect($validated)->except(['attendees', 'reminders'])->all(),
        );

        if (isset($validated['attendees'])) {
            $this->events->syncAttendees($event, $validated['attendees']);
        }

        if (isset($validated['reminders'])) {
            $this->events->syncReminders($event, $request->user(), $validated['reminders']);
        }

        return $this->occurrenceResponse($request, $event->load(['eventType', 'calendar']), 201);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $validated = $request->validated();

        $this->events->update(
            $event,
            collect($validated)->except(['attendees', 'reminders'])->all(),
        );

        if (isset($validated['attendees'])) {
            $this->events->syncAttendees($event, $validated['attendees']);
        }

        if (isset($validated['reminders'])) {
            $this->events->syncReminders($event, $request->user(), $validated['reminders']);
        }

        return $this->occurrenceResponse($request, $event->load(['eventType', 'calendar']));
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        $this->events->delete($event);

        return response()->json(status: 204);
    }

    private function occurrenceResponse(Request $request, Event $event, int $status = 200): JsonResponse
    {
        $preferenceColor = CalendarUserPreference::query()
            ->where('user_id', $request->user()->id)
            ->where('calendar_id', $event->calendar_id)
            ->value('color_override');

        $resource = new EventOccurrenceResource(
            EventOccurrence::fromEvent($event),
            $preferenceColor,
            $this->access->canWriteEvents($request->user(), $event->calendar),
        );

        return response()->json(['data' => $resource->toArray($request)], $status);
    }
}
