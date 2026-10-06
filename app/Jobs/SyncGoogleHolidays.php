<?php

namespace App\Jobs;

use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleHolidaySync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Import the organization's public holidays from Google (nightly, or on demand). */
class SyncGoogleHolidays implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function handle(GoogleHolidaySync $sync): void
    {
        if (blank(config('services.google.calendar_api_key'))) {
            return;
        }

        try {
            $sync->sync();
        } catch (GoogleApiException $e) {
            if ($e->error->transient()) {
                $this->release($e->retryAfter ?? 300 * $this->attempts());
            }
        }
    }
}
