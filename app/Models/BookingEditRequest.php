<?php

namespace App\Models;

use App\Enums\EditRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $booking_id
 * @property int $requested_by
 * @property EditRequestStatus $status
 * @property Carbon|null $proposed_starts_at
 * @property Carbon|null $proposed_ends_at
 * @property string|null $proposed_purpose
 * @property int|null $proposed_attendee_count
 * @property string|null $note
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 */
#[Fillable([
    'booking_id', 'requested_by', 'status', 'proposed_starts_at', 'proposed_ends_at',
    'proposed_purpose', 'proposed_attendee_count', 'note', 'reviewed_by',
    'reviewed_at', 'rejection_reason',
])]
class BookingEditRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => EditRequestStatus::class,
            'proposed_starts_at' => 'datetime',
            'proposed_ends_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
