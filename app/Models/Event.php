<?php

namespace App\Models;

use App\Enums\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $calendar_id
 * @property int|null $event_type_id
 * @property int|null $created_by
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property string|null $meeting_url
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $all_day
 * @property string $timezone
 * @property EventStatus $status
 * @property string|null $color
 * @property string $visibility
 * @property string|null $rrule
 * @property Carbon|null $recurrence_until
 * @property int|null $recurring_parent_id
 * @property Carbon|null $original_starts_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'calendar_id', 'event_type_id', 'created_by', 'title', 'description', 'location',
    'meeting_url', 'starts_at', 'ends_at', 'all_day', 'timezone', 'status', 'color',
    'visibility', 'rrule', 'recurrence_until', 'recurring_parent_id', 'original_starts_at',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'all_day' => false,
        'timezone' => 'Asia/Manila',
        'status' => 'confirmed',
        'visibility' => 'default',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'status' => EventStatus::class,
            'recurrence_until' => 'datetime',
            'original_starts_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /** @return BelongsTo<EventType, $this> */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Event, $this> */
    public function recurringParent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'recurring_parent_id');
    }

    /** @return HasMany<Event, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(Event::class, 'recurring_parent_id');
    }

    /** @return HasMany<EventAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    /** @return HasMany<EventReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(EventReminder::class);
    }

    public function isRecurring(): bool
    {
        return $this->rrule !== null;
    }

    public function isOverride(): bool
    {
        return $this->recurring_parent_id !== null;
    }

    /**
     * Root events (never overrides) that could place occurrences inside the
     * window: plain events overlapping it, or recurring series still running.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeWindowRoots(Builder $query, Carbon $start, Carbon $end): void
    {
        $query
            ->whereNull('recurring_parent_id')
            ->where(function (Builder $q) use ($start, $end) {
                $q
                    ->where(fn (Builder $plain) => $plain
                        ->whereNull('rrule')
                        ->where('starts_at', '<', $end)
                        ->where('ends_at', '>', $start))
                    ->orWhere(fn (Builder $recurring) => $recurring
                        ->whereNotNull('rrule')
                        ->where('starts_at', '<', $end)
                        ->where(fn (Builder $until) => $until
                            ->whereNull('recurrence_until')
                            ->orWhere('recurrence_until', '>', $start)));
            });
    }
}
