<?php

namespace App\Http\Controllers;

use App\Models\ChatRead;
use App\Models\Division;
use App\Services\Chat\ChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The full-page division group chat. The floating chat dock reuses the same
 * store and components; this page is the "maximized" surface.
 */
class DivisionChatController extends Controller
{
    public function __invoke(Request $request, ChatService $chat): Response|RedirectResponse
    {
        $user = $request->user();

        // Unit members land in their parent division's room.
        $division = $user->chatDivision();

        if ($division === null) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('DivisionChat', [
            'division' => [
                'id' => $division->id,
                'name' => $division->name,
                'photo_url' => $division->chat_photo_path === null
                    ? null
                    : route('api.chat.photo.show', absolute: false),
            ],
            'initialPage' => fn () => $chat->page($request, $division->id),
            'members' => fn () => $chat->members($division),
            'lastReadMessageId' => fn () => ChatRead::query()
                ->where('division_id', $division->id)
                ->where('user_id', $user->id)
                ->value('last_read_message_id'),
        ]);
    }
}
