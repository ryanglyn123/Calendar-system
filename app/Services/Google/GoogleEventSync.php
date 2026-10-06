<?php

namespace App\Services\Google;

use App\Enums\EventStatus;
use App\Enums\GoogleSyncError;
use App\Models\Event;
use App\Models\GoogleCalendar;
use App\Models\GoogleEvent;
use App\Services\Event\RecurrenceService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps one Google calendar's events mirrored into the app.
 *
 * Google's recommended flow: a full sync once (stored sync token), then
 * incremental syncs that return only what changed, deletions included.
 * A 410 means the token died: wipe the token and full-sync again; the
 * full sync sweeps away anything Google no longer returns.
 *
 * Every write is keyed by Google's event id, so a retried or doubled run
 * updates rows instead of duplicating them.
 */
class GoogleEventSync
{
    /** How far back the first sync reaches; incremental syncs follow every change afterwards. */
    public const HISTORY_MONTHS = 6;

    public function __construct(
        private readonly GoogleEventMapper $mapper,
        private readonly RecurrenceService $recurrence,
        private readonly GoogleBookingSync $bookings,
        private readonly GoogleMirror $mirror,
    ) {}

    public function sync(GoogleCalendar $calendar): void
    {
        $calendar->loadMissing('account.user');
        $account = $calendar->account;

        if (! $account->canReadEvents()) {
            $this->fail($calendar, $account->isActive() ? GoogleSyncError::MissingScope : GoogleSyncError::Reauth);

            return;
        }

        // The bookings calendar is only read to notice edits made in Google.
        if (! $calendar->is_bookings) {
            if (! $calendar->sync_enabled) {
                return;
            }

            $this->mirror->ensure($calendar);
        }

        try {
            $this->run($calendar, retryFull: true);
        } catch (GoogleApiException $e) {
            if (in_array($e->error, [GoogleSyncError::NotFound, GoogleSyncError::Forbidden], true)) {
                // Access is gone: what we mirrored is no longer the user's to see here.
                $this->mirror->clearEvents($calendar);
                $calendar->forceFill(['sync_token' => null])->save();
            }

            $this->fail($calendar, $e->error);

            if ($e->error->transient()) {
                throw $e;
            }
        }
    }

    private function run(GoogleCalendar $calendar, bool $retryFull): void
    {
        $full = $calendar->sync_token === null;
        $query = $full
            ? ['maxResults' => 2500, 'timeMin' => now()->subMonths(self::HISTORY_MONTHS)->startOfMonth()->toRfc3339String()]
            : ['maxResults' => 2500, 'syncToken' => $calendar->sync_token];

        $items = [];
        $pageToken = null;

        try {
            do {
                $page = GoogleCalendarClient::for($calendar->account)
                    ->events($calendar->google_id, $query + ($pageToken ? ['pageToken' => $pageToken] : []));

                array_push($items, ...($page['items'] ?? []));
                $pageToken = $page['nextPageToken'] ?? null;
            } while ($pageToken !== null);
        } catch (GoogleApiException $e) {
            if ($e->fullSyncRequired() && $retryFull) {
                $calendar->forceFill(['sync_token' => null])->save();

                $this->run($calendar, retryFull: false);

                return;
            }

            throw $e;
        }

        // The token only advances once every page is applied, so a run that
        // dies midway is simply repeated from the previous token.
        DB::transaction(function () use ($calendar, $items, $full, $page) {
            $calendar->is_bookings
                ? $this->applyToBookings($calendar, $items)
                : $this->apply($calendar, $items, $full);

            $calendar->forceFill([
                'sync_token' => $page['nextSyncToken'] ?? null,
                'last_synced_at' => now(),
                'sync_state' => 'idle',
                'sync_error' => null,
                'sync_error_at' => null,
            ])->save();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function apply(GoogleCalendar $calendar, array $items, bool $full): void
    {
        $user = $calendar->account->user;
        $timezone = $user->timezone ?: app(GeneralSettings::class)->timezone;

        // Series first, so exceptions can find the series they belong to.
        usort($items, fn (array $a, array $b) => isset($a['recurringEventId']) <=> isset($b['recurringEventId']));

        $seen = [];

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $seen[] = $id;

            // Our own bookings copied into Google are already on the
            // calendar as bookings; never mirror them a second time.
            if (isset($item['extendedProperties']['private'][GoogleBookingSync::BOOKING_KEY])) {
                continue;
            }

            try {
                ($item['status'] ?? null) === 'cancelled'
                    ? $this->remove($calendar, $item, $timezone)
                    : $this->upsert($calendar, $item, $user->id, $timezone);
            } catch (Throwable $e) {
                // One malformed event must not cost the rest of the calendar.
                Log::warning('Skipped a Google event that could not be mirrored', [
                    'calendar' => $calendar->id, 'event' => $id, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($full) {
            $this->sweep($calendar, $seen);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsert(GoogleCalendar $calendar, array $item, int $ownerId, string $timezone): void
    {
        $attributes = $this->mapper->attributes($item, $calendar, $ownerId, $timezone);
        $link = $calendar->events()->where('google_event_id', $item['id'])->with('event')->first();
        $parent = isset($item['recurringEventId']) ? $this->linked($calendar, (string) $item['recurringEventId']) : null;

        if ($parent !== null) {
            // A moved or edited instance replaces one slot of its series.
            $attributes['rrule'] = null;
            $attributes['recurring_parent_id'] = $parent->id;
            $attributes['original_starts_at'] = $this->mapper->originalStart($item, $timezone);
        }

        $event = $link?->event;

        if ($event === null && $parent !== null) {
            // An override may already exist for this slot (e.g. from an EXDATE).
            $event = Event::query()
                ->where('recurring_parent_id', $parent->id)
                ->where('original_starts_at', $attributes['original_starts_at'])
                ->first();
        }

        if ($event !== null) {
            $event->fill($attributes)->save();
        } else {
            $event = Event::create($attributes);
        }

        $event->forceFill(['recurrence_until' => $this->recurrence->computeRecurrenceUntil($event)])->save();

        GoogleEvent::updateOrCreate(
            ['google_calendar_id' => $calendar->id, 'google_event_id' => $item['id']],
            [
                'event_id' => $event->id,
                'recurring_event_id' => $item['recurringEventId'] ?? null,
                'etag' => $item['etag'] ?? null,
                'event_type' => $item['eventType'] ?? 'default',
                'busy' => $this->mapper->busy($item),
                'html_link' => $item['htmlLink'] ?? null,
                'details' => $this->mapper->details($item, $calendar) ?: null,
            ],
        );

        if ($attributes['rrule'] !== null) {
            $this->syncExcludedDates($event, $item, $attributes['timezone']);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function remove(GoogleCalendar $calendar, array $item, string $timezone): void
    {
        $link = $calendar->events()->where('google_event_id', $item['id'])->with('event')->first();

        if (! isset($item['recurringEventId'])) {
            // A whole event or series is gone; its overrides go with it.
            $link?->event?->forceDelete();

            return;
        }

        // One instance of a series was deleted: keep a cancelled override
        // so the expander skips that slot.
        $parent = $this->linked($calendar, (string) $item['recurringEventId']);
        $originalStart = $this->mapper->originalStart($item, $timezone);

        if ($parent === null || $originalStart === null) {
            return;
        }

        $event = $link->event ?? Event::query()
            ->where('recurring_parent_id', $parent->id)
            ->where('original_starts_at', $originalStart)
            ->first();

        $attributes = [
            'calendar_id' => $parent->calendar_id,
            'created_by' => $parent->created_by,
            'title' => $parent->title,
            'starts_at' => $originalStart,
            'ends_at' => $originalStart->addSeconds((int) $parent->starts_at->diffInSeconds($parent->ends_at)),
            'all_day' => $parent->all_day,
            'timezone' => $parent->timezone,
            'status' => EventStatus::Cancelled,
            'recurring_parent_id' => $parent->id,
            'original_starts_at' => $originalStart,
        ];

        if ($event !== null) {
            $event->fill($attributes)->save();
        } else {
            $event = Event::create($attributes);
        }

        GoogleEvent::updateOrCreate(
            ['google_calendar_id' => $calendar->id, 'google_event_id' => $item['id']],
            ['event_id' => $event->id, 'recurring_event_id' => $item['recurringEventId'], 'busy' => false],
        );
    }

    /**
     * EXDATE lines become cancelled overrides. They have no Google event
     * of their own, so they are rebuilt from the series each time.
     *
     * @param  array<string, mixed>  $item
     */
    private function syncExcludedDates(Event $series, array $item, string $timezone): void
    {
        $series->overrides()
            ->where('status', EventStatus::Cancelled->value)
            ->whereDoesntHave('googleEvent')
            ->forceDelete();

        $duration = (int) $series->starts_at->diffInSeconds($series->ends_at);

        foreach ($this->mapper->excludedStarts($item, $timezone) as $start) {
            Event::query()->firstOrCreate(
                ['recurring_parent_id' => $series->id, 'original_starts_at' => $start],
                [
                    'calendar_id' => $series->calendar_id,
                    'created_by' => $series->created_by,
                    'title' => $series->title,
                    'starts_at' => $start,
                    'ends_at' => $start->addSeconds($duration),
                    'all_day' => $series->all_day,
                    'timezone' => $series->timezone,
                    'status' => EventStatus::Cancelled,
                ],
            );
        }
    }

    /**
     * After a full sync, anything Google no longer returned was deleted
     * while we were not listening.
     *
     * @param  list<string>  $seen
     */
    private function sweep(GoogleCalendar $calendar, array $seen): void
    {
        $calendar->events()
            ->whereNotIn('google_event_id', $seen)
            ->with('event')
            ->get()
            ->each(fn (GoogleEvent $link) => $link->event?->forceDelete());
    }

    private function linked(GoogleCalendar $calendar, string $googleEventId): ?Event
    {
        return $calendar->events()->where('google_event_id', $googleEventId)->first()?->event;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function applyToBookings(GoogleCalendar $calendar, array $items): void
    {
        foreach ($items as $item) {
            $this->bookings->noticeGoogleChange($calendar->account, $item);
        }
    }

    private function fail(GoogleCalendar $calendar, GoogleSyncError $error): void
    {
        $calendar->forceFill([
            'sync_state' => 'error',
            'sync_error' => $error->value,
            'sync_error_at' => now(),
        ])->save();
    }
}
