<?php

namespace App\Http\Controllers\Api;

use App\Enums\CalendarType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\Calendar;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\ChatReaction;
use App\Models\Division;
use App\Models\Event;
use App\Models\Resource;
use App\Services\Calendar\CalendarAccessService;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Division chat endpoints. Membership is always an explicit division_id
 * comparison — never a permission check — because Gate::before would let
 * super admins into every division's room.
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $divisionId = $this->divisionIdOrAbort($request);

        $validated = $request->validate([
            'before_id' => ['sometimes', 'integer'],
        ]);

        return response()->json($this->chat->page(
            $request,
            $divisionId,
            isset($validated['before_id']) ? (int) $validated['before_id'] : null,
        ));
    }

    public function store(StoreChatMessageRequest $request, CalendarAccessService $access): JsonResponse
    {
        /** @var array{body?: string|null, reply_to_id?: int|null, event_id?: int|null, photos?: list<UploadedFile>, poll?: array{question: string, options: list<string>, allows_multiple?: bool}|null} $validated */
        $validated = $request->validated();

        // Shared event: the client sends only the id; the card contents are
        // snapshotted server-side after a visibility check on the sharer.
        if (isset($validated['event_id'])) {
            $event = Event::query()->with('calendar')->findOrFail((int) $validated['event_id']);

            abort_unless($access->canView($request->user(), $event->calendar), 403);

            $validated['event_card'] = $this->eventCard($event);
        }

        unset($validated['event_id']);

        return response()->json(
            ['data' => $this->chat->send($request, $request->user(), $validated)],
            201,
        );
    }

    /**
     * The snapshot a shared event renders as in chat — deliberately not the
     * full event, just enough to recognize it plus a deep link.
     *
     * @return array{event_id: int, title: string, starts_at: string, ends_at: string, all_day: bool, location: string|null, url: string}
     */
    private function eventCard(Event $event): array
    {
        $path = match ($event->calendar->type) {
            CalendarType::Organization => route('calendar.public', absolute: false),
            CalendarType::Division => route('calendar.division', absolute: false),
            CalendarType::Resource => $this->resourceSectionPath($event->calendar),
            CalendarType::Personal => route('dashboard', absolute: false),
        };

        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'all_day' => $event->all_day,
            'location' => $event->location,
            'url' => $path.'?event='.$event->id
                .'&date='.$event->starts_at->timezone($event->timezone)->toDateString(),
        ];
    }

    private function resourceSectionPath(Calendar $calendar): string
    {
        $resource = Resource::query()
            ->with('type')
            ->where('calendar_id', $calendar->id)
            ->first();

        return $resource?->type !== null
            ? route('resources.section', $resource->type, absolute: false)
            : route('dashboard', absolute: false);
    }

    public function destroy(Request $request, ChatMessage $message): JsonResponse
    {
        $this->assertSameDivision($request, $message->division_id);
        abort_unless($message->user_id === $request->user()->id, 403, 'You can only remove your own messages.');

        $this->chat->delete($message);

        return response()->json(['data' => ['id' => $message->id]]);
    }

    public function toggleReaction(Request $request, ChatMessage $message): JsonResponse
    {
        $this->assertSameDivision($request, $message->division_id);

        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in(ChatReaction::EMOJIS)],
        ]);

        return response()->json([
            'data' => $this->chat->toggleReaction($request->user(), $message, $validated['emoji']),
        ]);
    }

    public function togglePin(Request $request, ChatMessage $message): JsonResponse
    {
        $this->assertSameDivision($request, $message->division_id);

        return response()->json([
            'data' => ['is_pinned' => $this->chat->togglePin($request->user(), $message)],
        ]);
    }

    public function vote(Request $request, ChatPoll $poll): JsonResponse
    {
        $message = $poll->message()->withTrashed()->firstOrFail();
        $this->assertSameDivision($request, $message->division_id);

        $optionIds = $poll->options()->pluck('id')->all();

        $validated = $request->validate([
            'option_ids' => ['present', 'array', $poll->allows_multiple ? 'max:10' : 'max:1'],
            'option_ids.*' => ['integer', Rule::in($optionIds)],
        ]);

        return response()->json([
            'data' => $this->chat->vote(
                $request,
                $request->user(),
                $poll,
                array_map('intval', array_values(array_unique($validated['option_ids']))),
            ),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $this->divisionIdOrAbort($request);

        $validated = $request->validate([
            'last_read_message_id' => ['required', 'integer', 'min:1'],
        ]);

        $this->chat->markRead($request->user(), (int) $validated['last_read_message_id']);

        return response()->json(['data' => ['unread' => 0]]);
    }

    public function members(Request $request): JsonResponse
    {
        $divisionId = $this->divisionIdOrAbort($request);

        return response()->json([
            'data' => $this->chat->members(Division::query()->findOrFail($divisionId)),
        ]);
    }

    public function pinned(Request $request): JsonResponse
    {
        $divisionId = $this->divisionIdOrAbort($request);

        $messages = ChatMessage::query()
            ->where('division_id', $divisionId)
            ->whereNotNull('pinned_at')
            ->with(ChatService::MESSAGE_LOADS)
            ->orderByDesc('pinned_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $messages
                ->map(fn (ChatMessage $message) => ChatMessageResource::make($message)->resolve($request))
                ->values()
                ->all(),
        ]);
    }

    public function updatePhoto(Request $request): JsonResponse
    {
        $divisionId = $this->divisionIdOrAbort($request);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $this->chat->updatePhoto(
            $request->user(),
            Division::query()->findOrFail($divisionId),
            $request->file('photo'),
        );

        return response()->json(['data' => ['ok' => true]], 201);
    }

    public function showPhoto(Request $request): StreamedResponse
    {
        $divisionId = $this->divisionIdOrAbort($request);

        $path = Division::query()->findOrFail($divisionId)->chat_photo_path;

        abort_if($path === null, 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function showAttachment(Request $request, ChatAttachment $attachment): StreamedResponse
    {
        $message = $attachment->message()->withTrashed()->firstOrFail();
        $this->assertSameDivision($request, $message->division_id);

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * The chat room's division id: unit members resolve to their parent
     * division, so the whole hierarchy shares one room.
     */
    private function divisionIdOrAbort(Request $request): int
    {
        $division = $request->user()->chatDivision();

        abort_if($division === null, 403, 'You are not assigned to a division.');

        return $division->id;
    }

    private function assertSameDivision(Request $request, int $divisionId): void
    {
        abort_unless(
            $request->user()->chatDivision()?->id === $divisionId,
            403,
            'This chat belongs to another division.',
        );
    }
}
