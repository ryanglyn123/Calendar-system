<?php

namespace App\Jobs;

use App\Models\GoogleAccount;
use App\Models\GoogleCalendar;
use App\Services\Google\GoogleChannels;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The safety net under push notifications (which Google says can be
 * dropped, and which need a public HTTPS address at all): renew channels
 * before they expire and poll calendars that have been quiet too long.
 * Polls are incremental, so a quiet calendar costs one small request.
 */
class MaintainGoogleSync implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Without push, check this often. */
    private const POLL_MINUTES = 10;

    /** With push, still check now and then in case a notification was lost. */
    private const PUSHED_POLL_HOURS = 6;

    public function handle(GoogleChannels $channels): void
    {
        $pushed = GoogleChannels::available();
        $renewBefore = now()->addDay();

        GoogleAccount::query()
            ->where('status', GoogleAccount::STATUS_ACTIVE)
            ->each(function (GoogleAccount $account) use ($channels, $pushed, $renewBefore) {
                if ($pushed && ($account->channel_expires_at === null || $account->channel_expires_at->lt($renewBefore))) {
                    rescue(fn () => $channels->watchCalendarList($account), report: false);
                }

                if ($this->stale($account->last_synced_at, $pushed && $account->channel_id !== null)) {
                    SyncGoogleCalendarList::dispatch($account->id);
                }
            });

        GoogleCalendar::query()
            ->with('account')
            ->where(fn (Builder $q) => $q->where('sync_enabled', true)->orWhere('is_bookings', true))
            ->whereHas('account', fn (Builder $q) => $q->where('status', GoogleAccount::STATUS_ACTIVE))
            ->each(function (GoogleCalendar $calendar) use ($channels, $pushed, $renewBefore) {
                if ($pushed && ($calendar->channel_expires_at === null || $calendar->channel_expires_at->lt($renewBefore))) {
                    rescue(fn () => $channels->watchCalendar($calendar), report: false);
                }

                if ($this->stale($calendar->last_synced_at, $pushed && $calendar->channel_id !== null)) {
                    SyncGoogleCalendar::dispatch($calendar->id);
                }
            });
    }

    private function stale(mixed $lastSynced, bool $hasChannel): bool
    {
        return $lastSynced === null || $lastSynced->lt(
            $hasChannel ? now()->subHours(self::PUSHED_POLL_HOURS) : now()->subMinutes(self::POLL_MINUTES),
        );
    }
}
