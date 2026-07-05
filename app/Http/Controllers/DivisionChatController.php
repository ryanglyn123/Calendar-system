<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The full-page chat: a conversation list plus the open thread. The floating
 * dock reuses the same store and components; this page is the "maximized"
 * surface. Conversations themselves come from the shared props.
 */
class DivisionChatController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // Optional deep link (?conversation=ID); the page falls back to the
        // first conversation in the shared list when this is null.
        $requested = (int) $request->query('conversation', 0);

        $activeId = null;

        if ($requested > 0) {
            $conversation = Conversation::query()->find($requested);

            if ($conversation !== null && $conversation->isAccessibleTo($user)) {
                $activeId = $conversation->id;
            }
        }

        return Inertia::render('DivisionChat', [
            'initialConversationId' => $activeId,
        ]);
    }
}
