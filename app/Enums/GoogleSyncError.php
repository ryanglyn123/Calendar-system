<?php

namespace App\Enums;

/**
 * Why a Google sync step failed, in words a user can act on. Raw API
 * responses go to the log, never to the screen.
 */
enum GoogleSyncError: string
{
    case Reauth = 'reauth';
    case MissingScope = 'missing_scope';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case Network = 'network';
    case InvalidData = 'invalid_data';
    case NotConfigured = 'not_configured';

    public function message(): string
    {
        return match ($this) {
            self::Reauth => 'Google no longer accepts this connection. Reconnect your Google account to resume syncing.',
            self::MissingScope => 'Some Google permissions were not granted. Reconnect and allow the requested access.',
            self::Forbidden => 'Google refused access to this calendar. Its owner may have changed the sharing settings.',
            self::NotFound => 'This calendar no longer exists in Google Calendar, or it is no longer shared with you.',
            self::RateLimited => 'Google asked us to slow down. Syncing will resume automatically in a few minutes.',
            self::Unavailable => 'Google Calendar is having trouble right now. Syncing will retry automatically.',
            self::Network => 'Could not reach Google Calendar. Syncing will retry automatically.',
            self::InvalidData => 'Google sent something we could not read. The rest of the calendar was synced.',
            self::NotConfigured => 'Google Calendar is not set up on this server yet.',
        };
    }

    /** Whether waiting and retrying can fix it without anyone acting. */
    public function transient(): bool
    {
        return in_array($this, [self::RateLimited, self::Unavailable, self::Network], true);
    }
}
