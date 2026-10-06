<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $google_account_id
 * @property int|null $calendar_id
 * @property string $google_id
 * @property string $summary
 * @property string|null $time_zone
 * @property string $access_role
 * @property string|null $background_color
 * @property bool $is_primary
 * @property bool $is_holiday
 * @property bool $is_bookings
 * @property bool $sync_enabled
 * @property string|null $sync_token
 * @property Carbon|null $last_synced_at
 * @property string $sync_state
 * @property string|null $sync_error
 * @property Carbon|null $sync_error_at
 * @property string|null $channel_id
 * @property string|null $channel_resource_id
 * @property string|null $channel_token
 * @property Carbon|null $channel_expires_at
 * @property-read GoogleAccount $account
 * @property-read Calendar|null $calendar
 */
#[Fillable([
    'google_account_id', 'calendar_id', 'google_id', 'summary', 'time_zone', 'access_role',
    'background_color', 'is_primary', 'is_holiday', 'is_bookings', 'sync_enabled', 'sync_token',
    'last_synced_at', 'sync_state', 'sync_error', 'sync_error_at', 'channel_id',
    'channel_resource_id', 'channel_token', 'channel_expires_at',
])]
#[Hidden(['sync_token', 'channel_token'])]
class GoogleCalendar extends Model
{
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_holiday' => 'boolean',
            'is_bookings' => 'boolean',
            'sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'sync_error_at' => 'datetime',
            'channel_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GoogleAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'google_account_id');
    }

    /**
     * The app-side mirror calendar, present while syncing is on.
     *
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /** @return HasMany<GoogleEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(GoogleEvent::class);
    }

    /** Free/busy-only calendars carry times but no titles or details. */
    public function busyOnly(): bool
    {
        return $this->access_role === 'freeBusyReader';
    }
}
