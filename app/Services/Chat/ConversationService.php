<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\User;
use App\Support\EventLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads and writes on the conversation list: which rooms a user is in, their
 * summaries (name, photo, unread, last message), and creating/editing the
 * custom group chats.
 */
class ConversationService
{
    public function __construct(
        private readonly ChatService $chat,
    ) {}

    /**
     * Every conversation the user belongs to, newest-active first.
     *
     * @return Collection<int, Conversation>
     */
    public function forUser(User $user)
    {
        return Conversation::query()
            ->accessibleTo($user)
            ->with(['division', 'event.calendar', 'latestMessage.user'])
            ->get()
            ->sortByDesc(fn (Conversation $c) => optional($c->latestMessage)->id ?? 0)
            ->values();
    }

    /**
     * Summaries for the sidebar/dock list.
     *
     * @return list<array<string, mixed>>
     */
    public function summaries(User $user): array
    {
        return array_values($this->forUser($user)
            ->map(fn (Conversation $c): array => $this->summary($c, $user))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Conversation $conversation, User $user): array
    {
        $latest = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'name' => $this->displayName($conversation),
            'photo_url' => $this->photoUrl($conversation),
            'is_division' => $conversation->isDivision(),
            'created_by' => $conversation->created_by,
            'event' => $this->eventSummary($conversation),
            'unread' => $this->chat->unreadCount($user, $conversation),
            'last_preview' => $latest === null ? null : $this->preview($latest),
            'last_activity' => optional($latest)->created_at?->toIso8601String()
                ?? $conversation->created_at->toIso8601String(),
        ];
    }

    public function displayName(Conversation $conversation): string
    {
        if (! $conversation->isDivision()) {
            return $conversation->name ?? 'Group chat';
        }

        return $conversation->division->name;
    }

    public function photoUrl(Conversation $conversation): ?string
    {
        return $conversation->photo_path === null
            ? null
            : route('api.chat.photo.show', $conversation, absolute: false);
    }

    /**
     * Create a custom group chat. The creator is always a participant.
     *
     * @param  list<int>  $memberIds
     */
    public function createCustom(User $creator, string $name, array $memberIds, ?int $eventId = null): Conversation
    {
        return DB::transaction(function () use ($creator, $name, $memberIds, $eventId) {
            $conversation = Conversation::create([
                'type' => Conversation::TYPE_CUSTOM,
                'name' => $name,
                'event_id' => $eventId,
                'created_by' => $creator->id,
            ]);

            $ids = array_values(array_unique([...$memberIds, $creator->id]));
            $conversation->participants()->sync($ids);

            return $conversation;
        });
    }

    /**
     * Rename and/or reset the member list of a custom chat. The creator can
     * never be removed. Returns the fresh conversation.
     *
     * @param  list<int>|null  $memberIds
     */
    public function updateCustom(Conversation $conversation, ?string $name, ?array $memberIds): Conversation
    {
        if ($name !== null) {
            $conversation->update(['name' => $name]);
        }

        if ($memberIds !== null) {
            $ids = array_values(array_unique([...$memberIds, (int) $conversation->created_by]));
            $conversation->participants()->sync($ids);
        }

        return $conversation->refresh();
    }

    /**
     * Remove a member from a custom chat (or leave). The room is soft-deleted
     * once its last participant leaves.
     */
    public function leave(Conversation $conversation, User $user): void
    {
        $conversation->participants()->detach($user->id);

        if ($conversation->participants()->count() === 0) {
            $conversation->delete();
        }
    }

    /**
     * @return array{event_id: int, title: string, url: string}|null
     */
    private function eventSummary(Conversation $conversation): ?array
    {
        $event = $conversation->event;

        if (! $event instanceof Event || $event->calendar === null) {
            return null;
        }

        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'url' => EventLink::url($event),
        ];
    }

    private function preview(ChatMessage $message): string
    {
        if ($message->trashed()) {
            return 'Message removed';
        }

        if ($message->body !== null && $message->body !== '') {
            return Str::limit($message->body, 60);
        }

        return $message->poll()->exists() ? 'Poll'
            : ($message->attachments()->exists() ? 'Photo'
                : ($message->event_card !== null ? 'Shared an event' : ''));
    }
}
