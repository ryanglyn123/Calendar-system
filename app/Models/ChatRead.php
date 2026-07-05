<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int|null $division_id
 * @property int $user_id
 * @property int|null $last_read_message_id
 */
#[Fillable(['conversation_id', 'division_id', 'user_id', 'last_read_message_id'])]
class ChatRead extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
