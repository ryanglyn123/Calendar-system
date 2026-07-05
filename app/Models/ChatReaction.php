<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $message_id
 * @property int $user_id
 * @property string $emoji
 */
#[Fillable(['message_id', 'user_id', 'emoji'])]
class ChatReaction extends Model
{
    /** The curated messenger-style reaction set; validated server-side. */
    public const EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    /** @return BelongsTo<ChatMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
