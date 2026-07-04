<?php

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
    ) {}

    public function key(): string
    {
        return match ($this->booking->status) {
            BookingStatus::Approved => 'booking.approved',
            BookingStatus::Rejected => 'booking.rejected',
            default => 'booking.cancelled',
        };
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor($this->key());
    }

    private function summary(): string
    {
        $when = $this->booking->starts_at->timezone('Asia/Manila')->format('M j, H:i');

        return match ($this->booking->status) {
            BookingStatus::Approved => "Your booking of {$this->booking->resource?->name} on {$when} was approved.",
            BookingStatus::Rejected => "Your booking of {$this->booking->resource?->name} on {$when} was rejected: {$this->booking->rejection_reason}",
            default => "Your booking of {$this->booking->resource?->name} on {$when} was cancelled.",
        };
    }

    /** The booking's own section page (Boardroom, Carpool, ...). */
    private function sectionPath(): string
    {
        $slug = $this->booking->resource?->type?->slug;

        return $slug === null
            ? route('dashboard', absolute: false)
            : route('resources.section', $slug, absolute: false);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Booking '.$this->booking->status->label())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary())
            ->action('View my bookings', url($this->sectionPath()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => $this->key(),
            'title' => 'Booking '.$this->booking->status->label(),
            'message' => $this->summary(),
            'action_url' => $this->sectionPath(),
            'booking_id' => $this->booking->id,
        ];
    }
}
