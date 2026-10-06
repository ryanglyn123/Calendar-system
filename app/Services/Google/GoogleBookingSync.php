<?php

namespace App\Services\Google;

use App\Enums\BookingStatus;
use App\Enums\GoogleSyncError;
use App\Jobs\PushBookingToGoogle;
use App\Models\Booking;
use App\Models\GoogleAccount;
use App\Models\GoogleBookingEvent;
use App\Models\GoogleCalendar;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Log;

/**
 * Copies approved bookings into the requester's Google Calendar.
 *
 * The booking stays the source of truth: only an approved booking gets a
 * Google event; rejecting or cancelling removes it; an approved change
 * moves it. Edits made to the copy in Google never touch the booking —
 * they are noticed and shown, and the user can put the booking's version
 * back. A copy the user deleted in Google stays deleted until they ask.
 */
class GoogleBookingSync
{
    /** Private extended property linking a Google event to its booking. */
    public const BOOKING_KEY = 'fdcpBookingId';

    /** Queue a reconcile after the current transaction commits. Cheap when the requester never connected Google. */
    public static function queue(Booking $booking): void
    {
        if (GoogleAccount::query()->where('user_id', $booking->requested_by)->exists()) {
            PushBookingToGoogle::dispatch($booking->id)->afterCommit();
        }
    }

    /** Make Google match the booking. Idempotent: safe to run any number of times. */
    public function reconcile(Booking $booking, bool $restore = false): void
    {
        $booking->loadMissing('requester.googleAccount', 'event', 'resource');
        $account = $booking->requester?->googleAccount;

        if ($account === null) {
            return;
        }

        $link = $booking->googleEvents()->where('google_account_id', $account->id)->first();
        $wanted = $booking->status === BookingStatus::Approved && ! $booking->trashed();

        if (! $wanted) {
            $this->removeCopy($account, $link);

            return;
        }

        if (! $account->canPushBookings()) {
            return;
        }

        // The user deleted the copy in Google; respect that unless they ask for it back.
        if ($link?->state === GoogleBookingEvent::REMOVED_IN_GOOGLE && ! $restore) {
            return;
        }

        try {
            try {
                $event = $this->write($account, $this->bookingsCalendar($account), $booking, $link);
            } catch (GoogleApiException $e) {
                if ($e->error !== GoogleSyncError::NotFound) {
                    throw $e;
                }

                // The bookings calendar itself was deleted in Google: make a new one.
                $this->forgetBookingsCalendar($account);
                $link = null;
                $event = $this->write($account, $this->bookingsCalendar($account), $booking, null);
            }
        } catch (GoogleApiException $e) {
            $this->record($booking, $account, $link, GoogleBookingEvent::FAILED, error: $e->error);

            if ($e->error->transient()) {
                throw $e;
            }

            return;
        }

        $this->record($booking, $account, $link, GoogleBookingEvent::SYNCED, event: $event);
    }

    /**
     * A change Google reported on the bookings calendar (edited or deleted
     * there). Records it against the booking; never changes the booking.
     *
     * @param  array<string, mixed>  $item
     */
    public function noticeGoogleChange(GoogleAccount $account, array $item): void
    {
        $link = $account->bookingEvents()->where('google_event_id', $item['id'] ?? '')->with('booking')->first();

        // Our own write echoing back, or an event the user added there themselves.
        if ($link === null || ($item['etag'] ?? null) === $link->etag) {
            return;
        }

        if (($item['status'] ?? null) === 'cancelled') {
            $link->update(['state' => GoogleBookingEvent::REMOVED_IN_GOOGLE, 'etag' => null]);

            return;
        }

        $booking = $link->booking;
        $moved = $booking !== null && (
            ! $this->sameInstant($item['start']['dateTime'] ?? null, $booking->starts_at->toIso8601String())
            || ! $this->sameInstant($item['end']['dateTime'] ?? null, $booking->ends_at->toIso8601String())
        );

        $link->update([
            'etag' => $item['etag'] ?? null,
            // Colour or reminder tweaks are the user's business; only a
            // different time contradicts the booking.
            'state' => $moved ? GoogleBookingEvent::EDITED_IN_GOOGLE : $link->state,
        ]);
    }

    /** Google's event id for a booking: stable, so a retried insert can't duplicate. */
    public static function eventId(Booking $booking): string
    {
        // base32hex alphabet (a-v, 0-9) as Google requires for client ids.
        return 'fdcpbk'.str_pad((string) $booking->id, 8, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function write(GoogleAccount $account, string $calendarId, Booking $booking, ?GoogleBookingEvent $link): array
    {
        $client = GoogleCalendarClient::for($account);
        $body = $this->body($booking);
        $id = $link->google_event_id ?? self::eventId($booking);

        if ($link === null) {
            try {
                return $client->insertEvent($calendarId, ['id' => $id] + $body);
            } catch (GoogleApiException $e) {
                // Already there (an earlier attempt landed, or a cancelled
                // copy is being revived): update it instead.
                if (! $e->duplicate()) {
                    throw $e;
                }
            }
        }

        try {
            return $client->patchEvent($calendarId, $id, $body);
        } catch (GoogleApiException $e) {
            if ($e->error !== GoogleSyncError::NotFound) {
                throw $e;
            }

            return $client->insertEvent($calendarId, ['id' => $id] + $body);
        }
    }

    /** @return array<string, mixed> */
    private function body(Booking $booking): array
    {
        $settings = app(GeneralSettings::class);
        $title = $booking->event->title ?? $booking->purpose ?? 'Booking';
        $resource = $booking->resource;
        $url = route('bookings.show', $booking);

        $description = collect([
            $booking->purpose && $booking->purpose !== $title ? $booking->purpose : null,
            $booking->people ? 'People: '.implode(', ', $booking->people) : null,
            "{$settings->organization_acronym} booking {$booking->reference()} · approved",
            $url,
        ])->filter()->implode("\n\n");

        return [
            'summary' => $title,
            'location' => collect([$resource?->name, $resource?->location])->filter()->implode(', ') ?: null,
            'description' => $description,
            'start' => ['dateTime' => $booking->starts_at->toIso8601String(), 'timeZone' => $settings->timezone],
            'end' => ['dateTime' => $booking->ends_at->toIso8601String(), 'timeZone' => $settings->timezone],
            'status' => 'confirmed',
            'transparency' => 'opaque',
            'source' => ['title' => "{$settings->organization_acronym} Calendar", 'url' => $url],
            'extendedProperties' => ['private' => [self::BOOKING_KEY => (string) $booking->id]],
        ];
    }

    /** The app-created "Bookings" calendar, made on first use and remade if the user deleted it. */
    private function bookingsCalendar(GoogleAccount $account): string
    {
        if ($account->bookings_calendar_id !== null
            && $account->calendars()->where('google_id', $account->bookings_calendar_id)->exists()) {
            return $account->bookings_calendar_id;
        }

        $settings = app(GeneralSettings::class);
        $created = GoogleCalendarClient::for($account)->insertCalendar([
            'summary' => "{$settings->organization_acronym} Bookings",
            'description' => "Your approved room and vehicle bookings from {$settings->organization_name}. Managed by the {$settings->organization_acronym} Calendar app.",
            'timeZone' => $settings->timezone,
        ]);

        $account->forceFill(['bookings_calendar_id' => $created['id']])->save();

        GoogleCalendar::updateOrCreate(
            ['google_account_id' => $account->id, 'google_id' => $created['id']],
            [
                'summary' => $created['summary'] ?? "{$settings->organization_acronym} Bookings",
                'time_zone' => $created['timeZone'] ?? $settings->timezone,
                'access_role' => 'owner',
                'is_bookings' => true,
                'sync_enabled' => false,
            ],
        );

        // Existing links pointed into the old calendar; they are gone with it.
        $account->bookingEvents()->delete();

        return $created['id'];
    }

    private function forgetBookingsCalendar(GoogleAccount $account): void
    {
        if ($account->bookings_calendar_id !== null) {
            $account->calendars()->where('google_id', $account->bookings_calendar_id)->delete();
        }

        $account->forceFill(['bookings_calendar_id' => null])->save();
        $account->bookingEvents()->delete();
    }

    private function removeCopy(GoogleAccount $account, ?GoogleBookingEvent $link): void
    {
        if ($link === null) {
            return;
        }

        if ($link->state !== GoogleBookingEvent::REMOVED_IN_GOOGLE && $account->isActive() && $account->bookings_calendar_id) {
            try {
                GoogleCalendarClient::for($account)->deleteEvent($account->bookings_calendar_id, $link->google_event_id);
            } catch (GoogleApiException $e) {
                if ($e->error->transient()) {
                    throw $e;
                }

                Log::info('Could not remove a booking copy from Google', ['link' => $link->id, 'error' => $e->error->value]);
            }
        }

        $link->delete();
    }

    /** @param  array<string, mixed>  $event */
    private function record(
        Booking $booking,
        GoogleAccount $account,
        ?GoogleBookingEvent $link,
        string $state,
        array $event = [],
        ?GoogleSyncError $error = null,
    ): void {
        GoogleBookingEvent::updateOrCreate(
            ['booking_id' => $booking->id, 'google_account_id' => $account->id],
            array_filter([
                // Always our own id (Google echoes it back): it is what makes retries idempotent.
                'google_event_id' => $link->google_event_id ?? self::eventId($booking),
                'etag' => $event['etag'] ?? null,
                'html_link' => $event['htmlLink'] ?? null,
                'pushed_at' => $event === [] ? null : now(),
            ], fn ($value) => $value !== null) + [
                'state' => $state,
                'error' => $error?->value,
            ],
        );
    }

    private function sameInstant(?string $a, string $b): bool
    {
        return $a !== null && strtotime($a) === strtotime($b);
    }
}
