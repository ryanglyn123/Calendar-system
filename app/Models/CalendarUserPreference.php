<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $calendar_id
 * @property bool|null $is_visible null = follow the calendar-type default
 * @property string|null $color_override
 * @property int|null $sort_order
 */
#[Fillable(['user_id', 'calendar_id', 'is_visible', 'color_override', 'sort_order'])]
class CalendarUserPreference extends Model
{
    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
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
