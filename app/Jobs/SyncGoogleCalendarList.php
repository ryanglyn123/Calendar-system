<?php

namespace App\Jobs;

use App\Jobs\Concerns\BacksOffFromGoogle;
use App\Models\GoogleAccount;
use App\Services\Google\GoogleAccounts;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleChannels;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Refresh one account's calendar list, then sync each shown calendar. */
class SyncGoogleCalendarList implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use BacksOffFromGoogle, Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $googleAccountId) {}

    public function uniqueId(): string
    {
        return (string) $this->googleAccountId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("google-account:{$this->googleAccountId}"))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(GoogleAccounts $accounts, GoogleChannels $channels): void
    {
        $account = GoogleAccount::query()->find($this->googleAccountId);

        if ($account === null || ! $account->isActive()) {
            return;
        }

        try {
            $accounts->syncCalendarList($account);
        } catch (GoogleApiException $e) {
            if ($e->error->transient()) {
                $this->release($this->backoffFor($e));
            }

            return;
        }

        if ($account->channel_id === null) {
            rescue(fn () => $channels->watchCalendarList($account), report: false);
        }
    }
}
