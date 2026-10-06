<?php

namespace App\Services\Google;

use App\Enums\GoogleSyncError;
use App\Models\GoogleAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Access tokens for a connected account: refreshed shortly before they
 * expire, under a per-account lock so parallel jobs (several calendars
 * syncing at once) never race to refresh the same grant.
 */
class GoogleTokens
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function accessToken(GoogleAccount $account, bool $force = false): string
    {
        if (! $account->isActive()) {
            throw new GoogleApiException(GoogleSyncError::Reauth);
        }

        if (! $force && ! $this->expiring($account)) {
            return $account->access_token;
        }

        return Cache::lock("google-token:{$account->id}", 20)->block(15, function () use ($account, $force) {
            // Another worker may have refreshed while this one waited.
            $account->refresh();

            if (! $force && ! $this->expiring($account)) {
                return $account->access_token;
            }

            return $this->refreshNow($account);
        });
    }

    /** Tell Google to drop the grant. Best effort: disconnecting locally must not depend on it. */
    public function revoke(GoogleAccount $account): void
    {
        $token = $account->refresh_token ?? $account->access_token;

        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException) {
            // The grant stays listed in the user's Google account; they can remove it there.
        }
    }

    private function expiring(GoogleAccount $account): bool
    {
        return $account->token_expires_at === null || $account->token_expires_at->lte(now()->addMinute());
    }

    private function refreshNow(GoogleAccount $account): string
    {
        if ($account->refresh_token === null) {
            $this->requireReauth($account, 'no refresh token');
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);
        } catch (ConnectionException) {
            throw new GoogleApiException(GoogleSyncError::Network);
        }

        // Revoked, expired (e.g. 7-day tokens of an app still in Testing),
        // or blocked by a Workspace admin: only reconnecting fixes these.
        if (in_array($response->json('error'), ['invalid_grant', 'unauthorized_client', 'admin_policy_enforced'], true)) {
            $this->requireReauth($account, (string) $response->json('error'));
        }

        if ($response->failed()) {
            throw new GoogleApiException(
                $response->serverError() ? GoogleSyncError::Unavailable : GoogleSyncError::NotConfigured,
                $response->status(),
                is_string($response->json('error')) ? $response->json('error') : null,
            );
        }

        $account->forceFill([
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            // Users can grant scopes piecemeal; trust what Google reports.
            'scopes' => $response->json('scope')
                ? array_values(array_filter(explode(' ', (string) $response->json('scope'))))
                : $account->scopes,
        ])->save();

        return $account->access_token;
    }

    private function requireReauth(GoogleAccount $account, string $why): never
    {
        $account->forceFill(['status' => GoogleAccount::STATUS_REAUTH])->save();

        Log::info('Google Calendar connection needs reauthorisation', ['account' => $account->id, 'why' => $why]);

        throw new GoogleApiException(GoogleSyncError::Reauth, 401, $why);
    }
}
