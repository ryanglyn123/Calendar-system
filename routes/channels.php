<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Chat conversations. Access is a plain membership check (division ids for
 * division rooms, participant list for custom rooms) - never a permission
 * check, because Gate::before would let super admins into every private room.
 */
Broadcast::channel('conversation.{conversationId}.chat', function (User $user, int $conversationId) {
    if (! $user->isActive()) {
        return false;
    }

    $conversation = Conversation::query()->find($conversationId);

    return $conversation !== null && $conversation->isAccessibleTo($user);
});
