<?php

namespace App\Http\Controllers\Api;

use App\Enums\RsvpStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Notifications\EventRsvpChangedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventRsvpController extends Controller
{
    /**
     * Record the signed-in attendee's RSVP and tell the organizer.
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(RsvpStatus::class)],
        ]);

        $attendee = $event->attendees()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_if($attendee === null, 403, 'You are not invited to this event.');

        $status = RsvpStatus::from($validated['status']);

        $attendee->update([
            'rsvp_status' => $status,
            'responded_at' => now(),
        ]);

        $organizer = $event->creator;

        if ($organizer !== null && $organizer->isNot($request->user())) {
            $organizer->notify(new EventRsvpChangedNotification($event, $request->user(), $status));
        }

        return response()->json(['status' => $status->value]);
    }
}
