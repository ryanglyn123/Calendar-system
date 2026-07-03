<?php

namespace App\Models;

use App\Enums\SharePermission;
use Database\Factories\CalendarShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $calendar_id
 * @property int $user_id
 * @property SharePermission $permission
 * @property int|null $created_by
 */
#[Fillable(['calendar_id', 'user_id', 'permission', 'created_by'])]
class CalendarShare extends Model
{
    /** @use HasFactory<CalendarShareFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'permission' => SharePermission::class,
        ];
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
