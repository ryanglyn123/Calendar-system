<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property string $level info|warning|critical
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $division_id
 * @property bool $is_dismissible
 * @property bool $is_active
 * @property int|null $created_by
 */
#[Fillable([
    'title', 'body', 'level', 'starts_at', 'ends_at', 'division_id',
    'is_dismissible', 'is_active', 'created_by',
])]
class Announcement extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_dismissible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * Live announcements the given user should see right now.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            // Units belong to their parent division, so its announcements reach them too.
            ->where(fn (Builder $q) => $q->whereNull('division_id')
                ->orWhereIn('division_id', array_filter([$user->division_id, $user->division?->parent_id])))
            ->whereDoesntHave('dismissals', fn ($q) => $q->where('user_id', $user->id));
    }

    /** @return HasMany<AnnouncementDismissal, $this> */
    public function dismissals(): HasMany
    {
        return $this->hasMany(AnnouncementDismissal::class);
    }
}
