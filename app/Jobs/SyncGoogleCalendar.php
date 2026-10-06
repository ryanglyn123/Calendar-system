<?php

namespace App\Jobs;

use App\Jobs\Concerns\BacksOffFromGoogle;
use App\Models\GoogleCalendar;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleChannels;
use App\Services\Google\GoogleEventSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Incremental sync of one Google calendar. Unique until it starts, so a
 * burst of change notifications queues one run, and a change that lands
 * mid-run still queues the next; never two runs of one calendar at once.
 */
class SyncGoogleCalendar implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use BacksOffFromGoogle, Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $googleCalendarId) {}

    public function uniqueId(): string
    {
        return (string) $this->googleCalendarId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("google-calendar:{$this->googleCalendarId}"))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(GoogleEventSync $sync, GoogleChannels $channels): void
    {
        $calendar = GoogleCalendar::query()->with('account.user')->find($this->googleCalendarId);

        if ($calendar === null || ! $calendar->account->isActive()) {
            return;
        }

        try {
            $sync->sync($calendar);
        } catch (GoogleApiException $e) {
            // Rate limits and outages: back off (Google asks for exponential backoff).
            $this->release($this->backoffFor($e));

            return;
        }

        if (($calendar->sync_enabled || $calendar->is_bookings) && $calendar->channel_id === null) {
            rescue(fn () => $channels->watchCalendar($calendar), report: false);
        }
    }
}
