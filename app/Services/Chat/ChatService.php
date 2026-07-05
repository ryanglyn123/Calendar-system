<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatBroadcast;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\ChatRead;
use App\Models\Division;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * All division-chat writes plus the unread count. Broadcasts always fire
 * AFTER the transaction commits so no pre-commit state ever leaves the box.
 *
 * Membership is a plain division_id comparison everywhere on purpose:
 * Gate::before lets super admins pass every permission check, and chat
 * rooms must not leak across divisions even to them.
 */
class ChatService
{
    private const PAGE_SIZE = 30;

    /** Eager loads every serialization needs; keeps the endpoints N+1 free. */
    public const MESSAGE_LOADS = [
        'user', 'replyTo.user', 'attachments', 'reactions',
        'poll.options.votes.user',
    ];

    /**
     * Latest messages for the division, newest first, before the given id.
     *
     * @return array{messages: list<array<string, mixed>>, has_more: bool}
     */
    public function page(Request $request, int $divisionId, ?int $beforeId = null): array
    {
        $messages = ChatMessage::query()
            ->withTrashed()
            ->where('division_id', $divisionId)
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->with(self::MESSAGE_LOADS)
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $hasMore = $messages->count() > self::PAGE_SIZE;

        return [
            'messages' => array_values($messages
                ->take(self::PAGE_SIZE)
                ->map(fn (ChatMessage $message): array => ChatMessageResource::make($message)->resolve($request))
                ->all()),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  array{body?: string|null, reply_to_id?: int|null, event_card?: array<string, mixed>|null, photos?: list<UploadedFile>, poll?: array{question: string, options: list<string>, allows_multiple?: bool}|null}  $input
     * @return array<string, mixed> the serialized message
     */
    public function send(Request $request, User $user, array $input): array
    {
        $message = DB::transaction(function () use ($user, $input) {
            $message = ChatMessage::create([
                'division_id' => $user->division_id,
                'user_id' => $user->id,
                'body' => $input['body'] ?? null,
                'reply_to_id' => $input['reply_to_id'] ?? null,
                'event_card' => $input['event_card'] ?? null,
            ]);

            foreach ($input['photos'] ?? [] as $photo) {
                $path = $photo->storeAs(
                    "chat/{$user->division_id}",
                    Str::uuid().'.'.$photo->extension(),
                    'local',
                );

                [$width, $height] = @getimagesize($photo->getRealPath()) ?: [null, null];

                $message->attachments()->create([
                    'path' => $path,
                    'original_name' => $photo->getClientOriginalName(),
                    'mime' => $photo->getMimeType() ?? 'application/octet-stream',
                    'size' => (int) $photo->getSize(),
                    'width' => $width,
                    'height' => $height,
                ]);
            }

            if (isset($input['poll'])) {
                $poll = $message->poll()->create([
                    'question' => $input['poll']['question'],
                    'allows_multiple' => $input['poll']['allows_multiple'] ?? false,
                ]);

                foreach ($input['poll']['options'] as $index => $label) {
                    $poll->options()->create(['label' => $label, 'sort_order' => $index]);
                }
            }

            return $message;
        });

        $payload = ChatMessageResource::make(
            $message->load(self::MESSAGE_LOADS),
        )->resolve($request);

        broadcast(new ChatBroadcast($message->division_id, 'message.sent', $payload))->toOthers();

        return $payload;
    }

    /**
     * Soft delete leaves a tombstone; the content itself must not survive.
     */
    public function delete(ChatMessage $message): void
    {
        DB::transaction(function () use ($message) {
            foreach ($message->attachments as $attachment) {
                Storage::disk('local')->delete($attachment->path);
            }

            $message->attachments()->delete();
            $message->update(['body' => null]);
            $message->delete();
        });

        broadcast(new ChatBroadcast($message->division_id, 'message.deleted', [
            'id' => $message->id,
        ]))->toOthers();
    }

    /**
     * @return list<array{emoji: string, count: int, user_ids: list<int>}>
     */
    public function toggleReaction(User $user, ChatMessage $message, string $emoji): array
    {
        $existing = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            $message->reactions()->create(['user_id' => $user->id, 'emoji' => $emoji]);
        }

        $reactions = $message->load('reactions')->reactionSummary();

        broadcast(new ChatBroadcast($message->division_id, 'reaction.updated', [
            'message_id' => $message->id,
            'reactions' => $reactions,
        ]))->toOthers();

        return $reactions;
    }

    public function togglePin(User $user, ChatMessage $message): bool
    {
        $pinned = $message->pinned_at === null;

        $message->update([
            'pinned_at' => $pinned ? now() : null,
            'pinned_by' => $pinned ? $user->id : null,
        ]);

        broadcast(new ChatBroadcast($message->division_id, 'message.pinned', [
            'message_id' => $message->id,
            'is_pinned' => $pinned,
        ]))->toOthers();

        return $pinned;
    }

    /**
     * Sync the user's votes on a poll (idempotent PUT semantics).
     *
     * @param  list<int>  $optionIds  already validated to belong to the poll
     * @return array<string, mixed> the serialized poll
     */
    public function vote(Request $request, User $user, ChatPoll $poll, array $optionIds): array
    {
        DB::transaction(function () use ($user, $poll, $optionIds) {
            foreach ($poll->options as $option) {
                $option->votes()->where('user_id', $user->id)->delete();
            }

            foreach ($optionIds as $optionId) {
                $poll->options->firstOrFail(fn ($o) => $o->id === $optionId)
                    ->votes()->create(['user_id' => $user->id]);
            }
        });

        $message = $poll->message()->withTrashed()->firstOrFail()
            ->load(self::MESSAGE_LOADS);

        $payload = ChatMessageResource::make($message)->resolve($request);

        broadcast(new ChatBroadcast($message->division_id, 'poll.updated', [
            'message_id' => $message->id,
            'poll' => $payload['poll'],
        ]))->toOthers();

        return $payload['poll'];
    }

    /**
     * Monotonic: an out-of-order request can never move the marker back.
     * Read receipts are always on by design — there is no preference.
     */
    public function markRead(User $user, int $lastReadMessageId): void
    {
        $read = ChatRead::query()->firstOrCreate(
            ['division_id' => $user->division_id, 'user_id' => $user->id],
        );

        if (($read->last_read_message_id ?? 0) >= $lastReadMessageId) {
            return;
        }

        $read->update(['last_read_message_id' => $lastReadMessageId]);

        broadcast(new ChatBroadcast((int) $user->division_id, 'read.updated', [
            'user_id' => $user->id,
            'last_read_message_id' => $lastReadMessageId,
        ]))->toOthers();
    }

    public function unreadCount(User $user): int
    {
        if ($user->division_id === null) {
            return 0;
        }

        $lastRead = ChatRead::query()
            ->where('division_id', $user->division_id)
            ->where('user_id', $user->id)
            ->value('last_read_message_id') ?? 0;

        return ChatMessage::query()
            ->where('division_id', $user->division_id)
            ->where('id', '>', $lastRead)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->count();
    }

    /**
     * Division members with their read positions — the read-receipt bootstrap.
     *
     * @return list<array<string, mixed>>
     */
    public function members(Division $division): array
    {
        $reads = ChatRead::query()
            ->where('division_id', $division->id)
            ->pluck('last_read_message_id', 'user_id');

        return array_values($division->users()
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_path'])
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'avatar_path' => $member->avatar_path,
                'last_read_message_id' => $reads->get($member->id),
            ])
            ->all());
    }

    /**
     * Replace the division's group photo; any member may do this
     * (messenger semantics).
     */
    public function updatePhoto(User $user, Division $division, UploadedFile $photo): void
    {
        $old = $division->chat_photo_path;

        $path = $photo->storeAs(
            "chat/{$division->id}",
            'photo-'.Str::uuid().'.'.$photo->extension(),
            'local',
        );

        $division->update(['chat_photo_path' => $path]);

        if ($old !== null) {
            Storage::disk('local')->delete($old);
        }

        broadcast(new ChatBroadcast($division->id, 'settings.updated', [
            'photo_url' => route('api.chat.photo.show', absolute: false).'?v='.now()->timestamp,
            'updated_by' => $user->name,
        ]));
    }
}
