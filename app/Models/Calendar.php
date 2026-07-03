<?php

namespace App\Models;

use App\Enums\CalendarType;
use Database\Factories\CalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property CalendarType $type
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property string|null $icon
 * @property int|null $owner_id
 * @property int|null $division_id
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['type', 'name', 'description', 'color', 'icon', 'owner_id', 'division_id', 'is_default', 'is_active', 'sort_order', 'created_by'])]
class Calendar extends Model
{
    /** @use HasFactory<CalendarFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => CalendarType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** @return HasMany<CalendarShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(CalendarShare::class);
    }

    /** @return HasMany<CalendarUserPreference, $this> */
    public function userPreferences(): HasMany
    {
        return $this->hasMany(CalendarUserPreference::class);
    }

    public function isPersonal(): bool
    {
        return $this->type === CalendarType::Personal;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /**
     * Whether this calendar shows up in a user's sidebar when they have not
     * expressed an explicit visibility preference.
     */
    public function visibleByDefaultFor(User $user): bool
    {
        return match ($this->type) {
            CalendarType::Organization => $this->is_default,
            CalendarType::Division => $this->division_id === $user->division_id,
            CalendarType::Resource => false,
            CalendarType::Personal => true,
        };
    }

    /**
     * @param  Builder<Calendar>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'color', 'is_default', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
