<?php

namespace App\Models;

use App\Observers\DivisionObserver;
use Database\Factories\DivisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $acronym
 * @property string|null $description
 * @property string $color
 * @property string|null $chat_photo_path
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['parent_id', 'name', 'acronym', 'description', 'color', 'chat_photo_path', 'is_active', 'sort_order'])]
#[ObservedBy(DivisionObserver::class)]
class Division extends Model
{
    /** @use HasFactory<DivisionFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return BelongsTo<Division, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Units under this division (hierarchy is one level deep).
     *
     * @return HasMany<Division, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isUnit(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * @param  Builder<Division>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'acronym', 'color', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
