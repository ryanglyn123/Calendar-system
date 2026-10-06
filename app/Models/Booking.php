<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\EditRequestStatus;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
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
 * @property int $resource_id
 * @property int $event_id
 * @property int $requested_by
 * @property BookingStatus $status
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $purpose
 * @property int|null $attendee_count
 * @property list<string>|null $people the named attendees or passengers, in the requester's order
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 */
#[Fillable([
    'resource_id', 'event_id', 'requested_by', 'status', 'starts_at', 'ends_at',
    'purpose', 'attendee_count', 'people', 'reviewed_by', 'reviewed_at', 'rejection_reason',
    'cancelled_by', 'cancelled_at', 'cancellation_reason',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'people' => 'array',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** The number people quote on paper and on the phone: BK-000123. */
    public function reference(): string
    {
        return 'BK-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<\App\Models\Resource, $this> */
    public function resource(): BelongsTo
    {
        // Archived rooms/vehicles keep their bookings readable.
        return $this->belongsTo(Resource::class)->withTrashed();
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasMany<BookingEditRequest, $this> */
    public function editRequests(): HasMany
    {
        return $this->hasMany(BookingEditRequest::class);
    }

    /**
     * Copies of this booking in Google Calendar (the requester's).
     *
     * @return HasMany<GoogleBookingEvent, $this>
     */
    public function googleEvents(): HasMany
    {
        return $this->hasMany(GoogleBookingEvent::class);
    }

    public function hasPendingEditRequest(): bool
    {
        return $this->editRequests()
            ->where('status', EditRequestStatus::Pending)
            ->exists();
    }

    /**
     * Bookings that block the given range on a resource.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeBlocking(Builder $query, int $resourceId, Carbon|CarbonInterface $start, Carbon|CarbonInterface $end): void
    {
        $query
            ->where('resource_id', $resourceId)
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }
}
