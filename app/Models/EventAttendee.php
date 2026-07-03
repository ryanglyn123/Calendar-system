<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int|null $user_id
 * @property string|null $email
 * @property string|null $name
 * @property RsvpStatus $rsvp_status
 * @property bool $is_organizer
 * @property Carbon|null $responded_at
 */
#[Fillable(['event_id', 'user_id', 'email', 'name', 'rsvp_status', 'is_organizer', 'responded_at'])]
class EventAttendee extends Model
{
    protected function casts(): array
    {
        return [
            'rsvp_status' => RsvpStatus::class,
            'is_organizer' => 'boolean',
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
