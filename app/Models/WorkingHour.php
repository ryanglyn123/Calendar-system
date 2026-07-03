<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $resource_id null = organization default
 * @property int $day_of_week 0 = Sunday … 6 = Saturday
 * @property string|null $opens_at
 * @property string|null $closes_at
 */
#[Fillable(['resource_id', 'day_of_week', 'opens_at', 'closes_at'])]
class WorkingHour extends Model
{
    /** @return BelongsTo<\App\Models\Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The schedule row that applies to a resource on a weekday: the
     * resource's own row wins, otherwise the organization default.
     */
    public static function for(?int $resourceId, int $dayOfWeek): ?self
    {
        if ($resourceId !== null) {
            $specific = static::query()
                ->where('resource_id', $resourceId)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            if ($specific !== null) {
                return $specific;
            }
        }

        return static::query()
            ->whereNull('resource_id')
            ->where('day_of_week', $dayOfWeek)
            ->first();
    }

    public function isClosed(): bool
    {
        return $this->opens_at === null || $this->closes_at === null;
    }
}
