<?php

namespace App\Models;

use Database\Factories\EventTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $user_id Owner of a private category; null for shared types.
 * @property string $name
 * @property string $color
 * @property string|null $icon
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['user_id', 'name', 'color', 'icon', 'description', 'is_active', 'sort_order'])]
class EventType extends Model
{
    /** @use HasFactory<EventTypeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<EventType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Shared types plus the user's own private categories.
     *
     * @param  Builder<EventType>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
    }

    /**
     * Admin-managed types, without anyone's private categories.
     *
     * @param  Builder<EventType>  $query
     */
    public function scopeShared(Builder $query): void
    {
        $query->whereNull('user_id');
    }
}
