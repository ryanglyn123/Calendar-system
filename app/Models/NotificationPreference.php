<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $notification_key
 * @property bool $mail_enabled
 * @property bool $database_enabled
 */
#[Fillable(['user_id', 'notification_key', 'mail_enabled', 'database_enabled'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return [
            'mail_enabled' => 'boolean',
            'database_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
