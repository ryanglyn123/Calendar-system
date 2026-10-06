<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use App\Notifications\EventCommentedNotification;
use App\Services\Calendar\CalendarAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Discussion thread on an event. Access = "can view the event's calendar",
 * which already crosses divisions for org/resource calendars.
 */
class EventCommentController extends Controller
{
    public function __construct(
        private readonly CalendarAccessService $access,
    ) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        // Google events are a private read-only copy; discussion happens in Google.
        abort_if($event->isGoogleMirror(), 404);

        $this->assertCanView($request->user(), $event);

        // ponytail: no pagination — event threads are short; page when one isn't.
        $comments = $event->comments()->with('user')->orderBy('id')->get();

        return response()->json([
            'data' => array_values(
                $comments->map(fn (EventComment $comment) => $this->payload($comment))->all(),
            ),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        // Google events are a private read-only copy; discussion happens in Google.
        abort_if($event->isGoogleMirror(), 404);

        $user = $request->user();
        $this->assertCanView($user, $event);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $comment = $event->comments()->create([
            'user_id' => $user->id,
            'body' => $validated['body'],
        ]);

        $recipientIds = $event->attendees()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->push($event->created_by)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $id === $user->id);

        if ($recipientIds->isNotEmpty()) {
            Notification::send(
                User::query()->whereIn('id', $recipientIds)->get(),
                new EventCommentedNotification($event, $user, Str::limit($validated['body'], 120)),
            );
        }

        return response()->json(['data' => $this->payload($comment->load('user'))], 201);
    }

    public function destroy(Request $request, EventComment $comment): JsonResponse
    {
        abort_unless($comment->user_id === $request->user()->id, 403);

        $comment->delete();

        return response()->json(['ok' => true]);
    }

    private function assertCanView(User $user, Event $event): void
    {
        abort_unless($this->access->canView($user, $event->calendar), 403, 'You cannot view this event.');

        // Private events are masked to "Busy" for everyone else; the thread
        // must not leak what the mask hides.
        abort_if($event->visibility === 'private' && $event->created_by !== $user->id, 403, 'This event is private.');
    }

    /**
     * @return array{id: int, user: array{id: int, name: string, avatar_path: string|null}|null, body: string, created_at: string}
     */
    private function payload(EventComment $comment): array
    {
        return [
            'id' => $comment->id,
            'user' => $comment->user === null ? null : [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'avatar_path' => $comment->user->avatar_path,
            ],
            'body' => $comment->body,
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }
}
