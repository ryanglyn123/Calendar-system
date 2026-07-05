<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $message_id
 * @property string $question
 * @property bool $allows_multiple
 */
#[Fillable(['message_id', 'question', 'allows_multiple', 'closes_at'])]
class ChatPoll extends Model
{
    protected function casts(): array
    {
        return [
            'allows_multiple' => 'boolean',
            'closes_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    /** @return HasMany<ChatPollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ChatPollOption::class, 'poll_id');
    }
}
