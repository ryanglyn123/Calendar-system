<?php

namespace App\Services\Google;

use App\Enums\GoogleSyncError;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A failed Google call, classified once so callers branch on meaning
 * (retry later, reconnect, resync) rather than on raw status codes.
 */
class GoogleApiException extends RuntimeException
{
    public function __construct(
        public readonly GoogleSyncError $error,
        public readonly int $status = 0,
        public readonly ?string $reason = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct("Google Calendar API: {$error->value}".($reason ? " ({$reason})" : '')." [{$status}]");
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $reason = $response->json('error.errors.0.reason') ?? $response->json('error.status');
        $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;

        $error = match (true) {
            $status === 401 => GoogleSyncError::Reauth,
            $status === 429,
            in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true) => GoogleSyncError::RateLimited,
            $status === 403 && $reason === 'insufficientPermissions' => GoogleSyncError::MissingScope,
            $status === 403 => GoogleSyncError::Forbidden,
            $status === 404, $status === 410 && $reason === 'deleted' => GoogleSyncError::NotFound,
            $status >= 500 => GoogleSyncError::Unavailable,
            default => GoogleSyncError::InvalidData,
        };

        return new self($error, $status, is_string($reason) ? $reason : null, $retryAfter);
    }

    /** The sync token expired or the calendar's access changed: wipe and full-sync. */
    public function fullSyncRequired(): bool
    {
        return $this->status === 410 && $this->reason !== 'deleted';
    }

    /** An insert hit an id that already exists (our idempotency key). */
    public function duplicate(): bool
    {
        return $this->status === 409;
    }
}
