<?php

namespace App\Models;

use App\Services\Google\GoogleScopes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $google_sub
 * @property string $email
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property list<string> $scopes
 * @property string $status
 * @property bool $push_bookings
 * @property string|null $bookings_calendar_id
 * @property string|null $calendar_list_sync_token
 * @property string|null $channel_id
 * @property string|null $channel_resource_id
 * @property string|null $channel_token
 * @property Carbon|null $channel_expires_at
 * @property Carbon|null $last_synced_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'google_sub', 'email', 'access_token', 'refresh_token', 'token_expires_at',
    'scopes', 'status', 'push_bookings', 'bookings_calendar_id', 'calendar_list_sync_token',
    'channel_id', 'channel_resource_id', 'channel_token', 'channel_expires_at', 'last_synced_at',
])]
#[Hidden(['access_token', 'refresh_token', 'calendar_list_sync_token', 'channel_token'])]
class GoogleAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REAUTH = 'reauth_required';

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'push_bookings' => 'boolean',
            'channel_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<GoogleCalendar, $this> */
    public function calendars(): HasMany
    {
        return $this->hasMany(GoogleCalendar::class);
    }

    /** @return HasMany<GoogleBookingEvent, $this> */
    public function bookingEvents(): HasMany
    {
        return $this->hasMany(GoogleBookingEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function granted(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function canReadEvents(): bool
    {
        return $this->isActive() && $this->granted(GoogleScopes::EVENTS_READONLY);
    }

    public function canPushBookings(): bool
    {
        return $this->isActive() && $this->push_bookings && $this->granted(GoogleScopes::APP_CREATED);
    }
}
