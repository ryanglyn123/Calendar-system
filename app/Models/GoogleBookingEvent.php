<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An approved booking copied into its requester's Google calendar.
 *
 * @property int $id
 * @property int $booking_id
 * @property int $google_account_id
 * @property string $google_event_id
 * @property string|null $etag
 * @property string|null $html_link
 * @property string $state
 * @property string|null $error
 * @property Carbon|null $pushed_at
 */
#[Fillable([
    'booking_id', 'google_account_id', 'google_event_id', 'etag', 'html_link', 'state', 'error', 'pushed_at',
])]
class GoogleBookingEvent extends Model
{
    public const SYNCED = 'synced';

    public const EDITED_IN_GOOGLE = 'edited_in_google';

    public const REMOVED_IN_GOOGLE = 'removed_in_google';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'pushed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<GoogleAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'google_account_id');
    }
}
