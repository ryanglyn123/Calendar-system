<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGoogleCalendar;
use App\Jobs\SyncGoogleCalendarList;
use App\Models\GoogleAccount;
use App\Models\GoogleCalendar;
use App\Services\Google\GoogleChannels;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Google push notifications. They carry no event data, only
 * "something changed on channel X": we verify the channel's secret token
 * and queue an incremental sync. Unknown or stale channels are
 * acknowledged and ignored, so Google stops retrying them.
 */
class GoogleCalendarWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $channelId = $request->header('X-Goog-Channel-ID');
        $token = $request->header('X-Goog-Channel-Token');
        $resourceId = $request->header('X-Goog-Resource-ID');
        $state = $request->header('X-Goog-Resource-State');

        if (! is_string($channelId) || $channelId === '') {
            return response()->noContent(400);
        }

        $owner = GoogleCalendar::query()->where('channel_id', $channelId)->first()
            ?? GoogleAccount::query()->where('channel_id', $channelId)->first();

        if ($owner === null) {
            return response()->noContent();
        }

        if (! GoogleChannels::verify($owner, $token, $resourceId)) {
            return response()->noContent(403);
        }

        // "sync" just confirms a new channel; there is nothing to fetch yet.
        if ($state !== 'sync') {
            $owner instanceof GoogleCalendar
                ? SyncGoogleCalendar::dispatch($owner->id)
                : SyncGoogleCalendarList::dispatch($owner->id);
        }

        return response()->noContent();
    }
}
