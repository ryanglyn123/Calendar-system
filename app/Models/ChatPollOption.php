<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $poll_id
 * @property string $label
 * @property int $sort_order
 */
#[Fillable(['poll_id', 'label', 'sort_order'])]
class ChatPollOption extends Model
{
    /** @return BelongsTo<ChatPoll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(ChatPoll::class, 'poll_id');
    }

    /** @return HasMany<ChatPollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(ChatPollVote::class, 'option_id');
    }
}
