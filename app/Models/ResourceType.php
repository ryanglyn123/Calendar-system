<?php

namespace App\Models;

use Database\Factories\ResourceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property string|null $description
 * @property int $approver_role_id
 * @property bool $requires_approval
 * @property int $min_lead_time_minutes
 * @property int|null $max_duration_minutes
 * @property int|null $max_advance_days
 * @property int|null $max_active_bookings_per_user
 * @property bool $enforce_working_hours
 * @property bool $block_holidays
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable([
    'name', 'slug', 'icon', 'description', 'approver_role_id', 'requires_approval',
    'min_lead_time_minutes', 'max_duration_minutes', 'max_advance_days',
    'max_active_bookings_per_user', 'enforce_working_hours', 'block_holidays',
    'is_active', 'sort_order',
])]
class ResourceType extends Model
{
    /** @use HasFactory<ResourceTypeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'enforce_working_hours' => 'boolean',
            'block_holidays' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }

    /** @return HasMany<\App\Models\Resource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /**
     * @param  Builder<ResourceType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
