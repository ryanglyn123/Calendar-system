<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Google-side identity and display details of a mirrored event.
 *
 * @property int $id
 * @property int $google_calendar_id
 * @property int $event_id
 * @property string $google_event_id
 * @property string|null $recurring_event_id
 * @property string|null $etag
 * @property string $event_type
 * @property bool $busy
 * @property string|null $html_link
 * @property array<string, mixed>|null $details
 */
#[Fillable([
    'google_calendar_id', 'event_id', 'google_event_id', 'recurring_event_id', 'etag',
    'event_type', 'busy', 'html_link', 'details',
])]
class GoogleEvent extends Model
{
    protected function casts(): array
    {
        return [
            'busy' => 'boolean',
            'details' => 'array',
        ];
    }

    /** @return BelongsTo<GoogleCalendar, $this> */
    public function googleCalendar(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendar::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
