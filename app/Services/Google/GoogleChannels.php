<?php

namespace App\Services\Google;

use App\Models\GoogleAccount;
use App\Models\GoogleCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Google push notification channels: Google calls our webhook when a
 * watched calendar (or the calendar list) changes, and we answer by
 * running an incremental sync. Channels expire and are renewed ahead of
 * time; notifications can be dropped, so polling still backs them up.
 */
class GoogleChannels
{
    /** Ask for a week; Google may grant less and says so in the response. */
    private const TTL_SECONDS = 604800;

    /** Push needs a public HTTPS address with a valid certificate. */
    public static function available(): bool
    {
        return str_starts_with((string) config('services.google.calendar_webhook'), 'https://');
    }

    public function watchCalendar(GoogleCalendar $calendar): void
    {
        $this->watch($calendar, fn (array $channel) => GoogleCalendarClient::for($calendar->account)
            ->watchEvents($calendar->google_id, $channel));
    }

    public function watchCalendarList(GoogleAccount $account): void
    {
        $this->watch($account, fn (array $channel) => GoogleCalendarClient::for($account)
            ->watchCalendarList($channel));
    }

    /** Stop a channel; failure is harmless (it expires on its own). */
    public function stop(GoogleAccount|GoogleCalendar $owner): void
    {
        $this->stopRemote($owner, $owner->channel_id, $owner->channel_resource_id);

        $owner->forceFill([
            'channel_id' => null,
            'channel_resource_id' => null,
            'channel_token' => null,
            'channel_expires_at' => null,
        ])->save();
    }

    /** Whether an incoming notification really belongs to this channel. */
    public static function verify(GoogleAccount|GoogleCalendar $owner, ?string $token, ?string $resourceId): bool
    {
        return $owner->channel_token !== null
            && $token !== null
            && hash_equals($owner->channel_token, hash('sha256', $token))
            && ($owner->channel_resource_id === null || $owner->channel_resource_id === $resourceId);
    }

    /**
     * Open a new channel, then retire the old one: briefly both exist,
     * which only means a duplicate (harmless) notification.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $open
     */
    private function watch(GoogleAccount|GoogleCalendar $owner, callable $open): void
    {
        if (! self::available()) {
            return;
        }

        $token = Str::random(48);
        $response = $open([
            'id' => (string) Str::uuid(),
            'type' => 'web_hook',
            'address' => config('services.google.calendar_webhook'),
            'token' => $token,
            'params' => ['ttl' => (string) self::TTL_SECONDS],
        ]);

        [$oldId, $oldResource] = [$owner->channel_id, $owner->channel_resource_id];

        $owner->forceFill([
            'channel_id' => $response['id'],
            'channel_resource_id' => $response['resourceId'] ?? null,
            // Only a hash is kept; the raw token travels with each notification.
            'channel_token' => hash('sha256', $token),
            'channel_expires_at' => isset($response['expiration'])
                ? Carbon::createFromTimestampMs((int) $response['expiration'])
                : now()->addSeconds(self::TTL_SECONDS),
        ])->save();

        if ($oldId !== null && $oldId !== $response['id']) {
            $this->stopRemote($owner, $oldId, $oldResource);
        }
    }

    private function stopRemote(GoogleAccount|GoogleCalendar $owner, ?string $channelId, ?string $resourceId): void
    {
        $account = $owner instanceof GoogleAccount ? $owner : $owner->account;

        if ($channelId === null || $resourceId === null || ! $account->isActive()) {
            return;
        }

        try {
            GoogleCalendarClient::for($account)->stopChannel($channelId, $resourceId);
        } catch (GoogleApiException $e) {
            Log::info('Could not stop a Google channel; it will expire', ['channel' => $channelId, 'error' => $e->error->value]);
        }
    }
}
