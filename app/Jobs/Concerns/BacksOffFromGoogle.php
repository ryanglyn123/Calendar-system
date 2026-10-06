<?php

namespace App\Jobs\Concerns;

use App\Services\Google\GoogleApiException;

/**
 * Exponential backoff for Google rate limits and outages, as Google asks:
 * honour Retry-After when sent, else 30s, 60s, 120s... capped at 15 min.
 */
trait BacksOffFromGoogle
{
    protected function backoffFor(GoogleApiException $e): int
    {
        return $e->retryAfter ?? min(900, 30 << max(0, $this->attempts() - 1));
    }
}
