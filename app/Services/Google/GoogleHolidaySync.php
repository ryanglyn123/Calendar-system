<?php

namespace App\Services\Google;

use App\Enums\GoogleSyncError;
use App\Models\Holiday;
use App\Settings\GoogleCalendarSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fills the holidays list from Google's public holiday calendars, read
 * with the server's API key (no user account involved).
 *
 * Admins stay in charge: an imported holiday they edit is marked
 * overridden and later imports leave it alone; one they delete stays
 * deleted (soft-deleted, so the import remembers).
 */
class GoogleHolidaySync
{
    public function __construct(private readonly GoogleCalendarSettings $settings) {}

    public function sync(): void
    {
        try {
            DB::transaction(function () {
                foreach ($this->settings->holiday_calendar_ids as $calendarId) {
                    $this->syncCalendar($calendarId);
                }
            });
        } catch (GoogleApiException $e) {
            $this->settings->holidays_sync_error = $e->error->value;
            $this->settings->save();

            throw $e;
        }

        $this->settings->holidays_synced_at = now()->toIso8601String();
        $this->settings->holidays_sync_error = null;
        $this->settings->save();
    }

    private function syncCalendar(string $calendarId): void
    {
        $from = CarbonImmutable::now()->subYear()->startOfYear();
        $to = CarbonImmutable::now()->addYears(2)->startOfYear();
        $seen = [];
        $pageToken = null;

        do {
            $page = GoogleCalendarClient::public()->events($calendarId, array_filter([
                'timeMin' => $from->toRfc3339String(),
                'timeMax' => $to->toRfc3339String(),
                'singleEvents' => true,
                'maxResults' => 2500,
                'pageToken' => $pageToken,
            ]));

            foreach ($page['items'] ?? [] as $item) {
                if (($item['status'] ?? null) === 'cancelled' || ! isset($item['start']['date'])) {
                    continue;
                }

                $seen[] = $item['id'];
                $this->upsert($calendarId, $item);
            }

            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        // Holidays Google dropped (moved or withdrawn), unless an admin owns them now.
        Holiday::query()
            ->where('google_calendar_id', $calendarId)
            ->where('overridden', false)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('google_event_id', $seen)
            ->forceDelete();
    }

    /** @param  array<string, mixed>  $item */
    private function upsert(string $calendarId, array $item): void
    {
        $holiday = Holiday::withTrashed()
            ->where('google_calendar_id', $calendarId)
            ->where('google_event_id', $item['id'])
            ->first();

        if ($holiday !== null && ($holiday->overridden || $holiday->trashed())) {
            return;
        }

        [$type, $blocks] = $this->classify((string) ($item['description'] ?? ''));

        Holiday::updateOrCreate(
            ['google_calendar_id' => $calendarId, 'google_event_id' => $item['id']],
            [
                'name' => mb_substr(trim((string) ($item['summary'] ?? 'Holiday')), 0, 255),
                'date' => $item['start']['date'],
                'recurs_annually' => false,
                'type' => $type,
                'blocks_bookings' => $blocks,
                'source' => 'google',
            ],
        );
    }

    /**
     * Google labels entries in the description ("Public holiday",
     * "Observance"...). The wording is not a documented API contract, so
     * unknown text falls back to a non-blocking observance and admins can
     * reclassify any day.
     *
     * @return array{0: string, 1: bool}
     */
    private function classify(string $description): array
    {
        $text = strtolower($description);

        return match (true) {
            str_contains($text, 'special') => ['special_non_working', true],
            str_contains($text, 'public holiday'), str_contains($text, 'regular holiday') => ['regular', true],
            default => ['observance', false],
        };
    }

    public static function lastError(): ?string
    {
        $code = app(GoogleCalendarSettings::class)->holidays_sync_error;

        return $code === null ? null : GoogleSyncError::tryFrom($code)?->message();
    }
}
