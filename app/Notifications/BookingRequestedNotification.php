<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\Concerns\WritesInAppCopyNow;
use App\Settings\GeneralSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable, WritesInAppCopyNow;

    public const KEY = 'booking.requested';

    public function __construct(
        public readonly Booking $booking,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(self::KEY);
    }

    /** "Boardroom request needs review": the type comes first, so a
     *  Carpool request can never be mistaken for a Boardroom one. */
    private function title(): string
    {
        return ($this->booking->resource?->type?->name ?? 'Booking').' request needs review';
    }

    /** The one booking's review page, whatever has happened to it since. */
    private function reviewUrl(bool $absolute = true): string
    {
        return route('admin.bookings.show', $this->booking->id, absolute: $absolute);
    }

    private function summary(): string
    {
        return sprintf(
            '%s requested %s on %s.',
            $this->booking->requester?->name,
            $this->booking->resource?->name,
            $this->booking->starts_at->timezone(app(GeneralSettings::class)->timezone)->format('M j, H:i'),
        );
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary())
            ->lineIf((bool) $this->booking->purpose, "Purpose: {$this->booking->purpose}")
            ->action('Review this request', $this->reviewUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => $this->title(),
            'message' => $this->summary(),
            'action_url' => $this->reviewUrl(absolute: false),
            'booking_id' => $this->booking->id,
            'resource_type' => $this->booking->resource?->type?->slug,
        ];
    }
}
