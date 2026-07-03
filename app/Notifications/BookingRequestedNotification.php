<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

    private function summary(): string
    {
        return sprintf(
            '%s requested %s on %s.',
            $this->booking->requester?->name,
            $this->booking->resource?->name,
            $this->booking->starts_at->timezone('Asia/Manila')->format('M j, H:i'),
        );
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Booking approval needed')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary())
            ->lineIf((bool) $this->booking->purpose, "Purpose: {$this->booking->purpose}")
            ->action('Review pending bookings', route('admin.bookings.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => 'Booking approval needed',
            'message' => $this->summary(),
            'action_url' => route('admin.bookings.index', absolute: false),
            'booking_id' => $this->booking->id,
        ];
    }
}
