<?php

namespace App\Http\Resources;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatPollOption;
use App\Models\ChatPollVote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * The one serialization of a chat message, shared by the HTTP endpoints,
 * the page's inlined first paint, and the message.sent broadcast payload.
 *
 * Expects eager loads: user, replyTo.user, attachments, reactions,
 * poll.options.votes.user.
 *
 * @mixin ChatMessage
 */
class ChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deleted = $this->trashed();

        return [
            'id' => $this->id,
            'user' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_path' => $this->user->avatar_path,
            ],
            'is_deleted' => $deleted,
            'body' => $deleted ? null : $this->body,
            'reply_to' => $deleted || $this->replyTo === null ? null : [
                'id' => $this->replyTo->id,
                'user_name' => $this->replyTo->user?->name,
                'excerpt' => $this->replyTo->trashed()
                    ? null
                    : Str::limit((string) $this->replyTo->body, 90),
            ],
            'attachments' => $deleted ? [] : $this->attachments
                ->map(fn (ChatAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'url' => route('api.chat.attachments.show', $attachment, absolute: false),
                    'original_name' => $attachment->original_name,
                    'width' => $attachment->width,
                    'height' => $attachment->height,
                ])->values()->all(),
            'event_card' => $deleted ? null : $this->event_card,
            'reactions' => $this->reactionSummary(),
            'poll' => $this->poll === null ? null : [
                'id' => $this->poll->id,
                'question' => $this->poll->question,
                'allows_multiple' => $this->poll->allows_multiple,
                'options' => $this->poll->options
                    ->sortBy('sort_order')
                    ->map(fn (ChatPollOption $option): array => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'voters' => $option->votes
                            ->map(fn (ChatPollVote $vote): array => [
                                'id' => $vote->user_id,
                                'name' => $vote->user->name,
                            ])->values()->all(),
                    ])->values()->all(),
            ],
            'is_pinned' => $this->pinned_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
