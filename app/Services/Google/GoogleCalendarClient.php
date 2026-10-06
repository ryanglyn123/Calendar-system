<?php

namespace App\Services\Google;

use App\Enums\GoogleSyncError;
use App\Models\GoogleAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Google Calendar API v3 REST endpoints, acting as one
 * connected account (or, for public holiday calendars, with the server's
 * API key and no account). Every Google call in the app goes through here.
 */
class GoogleCalendarClient
{
    public const BASE = 'https://www.googleapis.com/calendar/v3';

    private function __construct(
        private readonly ?GoogleAccount $account,
        private readonly GoogleTokens $tokens,
    ) {}

    public static function for(GoogleAccount $account): self
    {
        return new self($account, app(GoogleTokens::class));
    }

    /** Anonymous access to public calendars with the server's API key. */
    public static function public(): self
    {
        if (blank(config('services.google.calendar_api_key'))) {
            throw new GoogleApiException(GoogleSyncError::NotConfigured);
        }

        return new self(null, app(GoogleTokens::class));
    }

    // --- Calendar list and calendars -------------------------------------

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function calendarList(array $query): array
    {
        return $this->send('GET', '/users/me/calendarList', query: $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function insertCalendar(array $body): array
    {
        return $this->send('POST', '/calendars', body: $body);
    }

    // --- Events ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function events(string $calendarId, array $query): array
    {
        return $this->send('GET', '/calendars/'.rawurlencode($calendarId).'/events', query: $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function insertEvent(string $calendarId, array $body): array
    {
        return $this->send('POST', '/calendars/'.rawurlencode($calendarId).'/events', body: $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patchEvent(string $calendarId, string $eventId, array $body): array
    {
        return $this->send('PATCH', $this->eventPath($calendarId, $eventId), body: $body);
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->send('DELETE', $this->eventPath($calendarId, $eventId));
    }

    // --- Push channels ---------------------------------------------------

    /**
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>
     */
    public function watchEvents(string $calendarId, array $channel): array
    {
        return $this->send('POST', '/calendars/'.rawurlencode($calendarId).'/events/watch', body: $channel);
    }

    /**
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>
     */
    public function watchCalendarList(array $channel): array
    {
        return $this->send('POST', '/users/me/calendarList/watch', body: $channel);
    }

    public function stopChannel(string $id, string $resourceId): void
    {
        $this->send('POST', '/channels/stop', body: ['id' => $id, 'resourceId' => $resourceId]);
    }

    // --- Availability ----------------------------------------------------

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function freeBusy(array $body): array
    {
        return $this->send('POST', '/freeBusy', body: $body);
    }

    // ---------------------------------------------------------------------

    private function eventPath(string $calendarId, string $eventId): string
    {
        return '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $response = $this->attempt($method, $path, $query, $body, $this->token());

        // An access token can die before its expiry (revoked, rotated):
        // refresh once and retry before giving up on the connection.
        if ($response->status() === 401 && $this->account !== null) {
            $response = $this->attempt($method, $path, $query, $body, $this->token(force: true));
        }

        if ($response->failed()) {
            throw GoogleApiException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    private function token(bool $force = false): ?string
    {
        return $this->account === null ? null : $this->tokens->accessToken($this->account, $force);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function attempt(string $method, string $path, array $query, ?array $body, ?string $token): Response
    {
        $request = $this->request($token);

        if ($token === null) {
            $query['key'] = config('services.google.calendar_api_key');
        }

        try {
            return $request->send($method, self::BASE.$path.($query === [] ? '' : '?'.$this->query($query)), $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            throw new GoogleApiException(GoogleSyncError::Network);
        }
    }

    private function request(?string $token): PendingRequest
    {
        $request = Http::acceptJson()->connectTimeout(10)->timeout(30);

        return $token === null ? $request : $request->withToken($token);
    }

    /**
     * Google repeats array parameters (eventTypes=a&eventTypes=b) rather
     * than PHP's bracket form.
     *
     * @param  array<string, mixed>  $query
     */
    private function query(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                $pairs[] = rawurlencode($key).'='.rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }

        return implode('&', $pairs);
    }
}
