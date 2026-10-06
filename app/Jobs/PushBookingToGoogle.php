<?php

namespace App\Jobs;

use App\Jobs\Concerns\BacksOffFromGoogle;
use App\Models\Booking;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleBookingSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Make the requester's Google Calendar match a booking's current state.
 * It reads the booking fresh when it runs, so out-of-order or repeated
 * dispatches all converge on the same result.
 */
class PushBookingToGoogle implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use BacksOffFromGoogle, Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $bookingId, public readonly bool $restore = false) {}

    public function uniqueId(): string
    {
        return $this->bookingId.($this->restore ? ':restore' : '');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("google-booking:{$this->bookingId}"))->releaseAfter(15)->expireAfter(300)];
    }

    public function handle(GoogleBookingSync $sync): void
    {
        $booking = Booking::withTrashed()->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        try {
            $sync->reconcile($booking, $this->restore);
        } catch (GoogleApiException $e) {
            $this->release($this->backoffFor($e));
        }
    }
}
