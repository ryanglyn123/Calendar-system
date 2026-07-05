<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\User;
use App\Services\Chat\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The conversation list plus custom group-chat management. Division rooms are
 * created with their division and are not editable here.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->conversations->summaries($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'member_ids' => ['required', 'array', 'min:1', 'max:100'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
            'event_id' => ['nullable', 'integer', Rule::exists(Event::class, 'id')->whereNull('deleted_at')],
        ]);

        $conversation = $this->conversations->createCustom(
            $user,
            $validated['name'],
            array_values(array_map('intval', $validated['member_ids'])),
            isset($validated['event_id']) ? (int) $validated['event_id'] : null,
        );

        return response()->json(
            ['data' => $this->conversations->summary($conversation->fresh(['latestMessage', 'event.calendar']), $user)],
            201,
        );
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $this->assertManageableCustom($request, $conversation);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'member_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ]);

        $updated = $this->conversations->updateCustom(
            $conversation,
            $validated['name'] ?? null,
            isset($validated['member_ids']) ? array_values(array_map('intval', $validated['member_ids'])) : null,
        );

        return response()->json(
            ['data' => $this->conversations->summary($updated->fresh(['latestMessage', 'event.calendar']), $request->user())],
        );
    }

    public function leave(Request $request, Conversation $conversation): JsonResponse
    {
        $this->assertManageableCustom($request, $conversation);

        $this->conversations->leave($conversation, $request->user());

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Members may search teammates to add — any active user, any division. */
    public function participantCandidates(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $users = User::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'avatar_path']);

        return response()->json(['data' => $users]);
    }

    private function assertManageableCustom(Request $request, Conversation $conversation): void
    {
        abort_if($conversation->isDivision(), 403, 'Division rooms cannot be edited.');
        abort_unless($conversation->isAccessibleTo($request->user()), 403);
    }
}
