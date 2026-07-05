<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $division_id
 * @property int|null $user_id
 * @property string|null $body
 * @property int|null $reply_to_id
 * @property array<string, mixed>|null $event_card
 * @property CarbonInterface|null $pinned_at
 * @property int|null $pinned_by
 * @property CarbonInterface $created_at
 */
#[Fillable(['division_id', 'user_id', 'body', 'reply_to_id', 'event_card', 'pinned_at', 'pinned_by'])]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'event_card' => 'array',
            'pinned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /** @return HasMany<ChatAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }

    /** @return HasMany<ChatReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }

    /** @return HasOne<ChatPoll, $this> */
    public function poll(): HasOne
    {
        return $this->hasOne(ChatPoll::class, 'message_id');
    }

    /**
     * Reactions aggregated per emoji, the shape both the message payload
     * and the reaction.updated broadcast use.
     *
     * @return list<array{emoji: string, count: int, user_ids: list<int>}>
     */
    public function reactionSummary(): array
    {
        $summary = [];

        foreach ($this->reactions as $reaction) {
            if (! isset($summary[$reaction->emoji])) {
                $summary[$reaction->emoji] = [
                    'emoji' => $reaction->emoji,
                    'count' => 0,
                    'user_ids' => [],
                ];
            }

            $summary[$reaction->emoji]['count']++;
            $summary[$reaction->emoji]['user_ids'][] = (int) $reaction->user_id;
        }

        return array_values($summary);
    }
}
