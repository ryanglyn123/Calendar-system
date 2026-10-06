<?php

namespace App\Services\Google;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * When the user is busy in Google Calendar, across all their calendars
 * (shown or not), via freebusy.query. Used as a hint while booking; it
 * never decides whether a booking is allowed.
 */
class GoogleFreeBusy
{
    /** Google's limit on calendars per freebusy.query. */
    private const MAX_CALENDARS = 50;

    /**
     * Busy ranges inside [from, to), merged and sorted; null when the user
     * has no usable connection.
     *
     * @return list<array{starts_at: string, ends_at: string}>|null
     */
    public function busy(User $user, CarbonInterface $from, CarbonInterface $to): ?array
    {
        $account = $user->googleAccount;

        if ($account === null || ! $account->isActive() || ! $account->granted(GoogleScopes::FREEBUSY)) {
            return null;
        }

        $key = "google-busy:{$account->id}:{$from->getTimestamp()}:{$to->getTimestamp()}";

        return Cache::remember($key, now()->addMinutes(2), function () use ($account, $from, $to) {
            // Bookings copies and holidays are not "you are busy elsewhere".
            $ids = $account->calendars()
                ->where('is_bookings', false)
                ->where('is_holiday', false)
                ->orderByDesc('is_primary')
                ->limit(self::MAX_CALENDARS)
                ->pluck('google_id')
                ->all();

            if ($ids === []) {
                return [];
            }

            $response = GoogleCalendarClient::for($account)->freeBusy([
                'timeMin' => $from->toRfc3339String(),
                'timeMax' => $to->toRfc3339String(),
                'items' => array_map(fn (string $id) => ['id' => $id], $ids),
            ]);

            $ranges = [];

            foreach ($response['calendars'] ?? [] as $calendar) {
                // A calendar that answered with errors simply contributes nothing.
                foreach ($calendar['busy'] ?? [] as $slot) {
                    $ranges[] = [strtotime($slot['start']), strtotime($slot['end'])];
                }
            }

            return $this->merge($ranges);
        });
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<array{starts_at: string, ends_at: string}>
     */
    private function merge(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($ranges as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return array_map(fn (array $r) => [
            'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $r[0]),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $r[1]),
        ], $merged);
    }
}
