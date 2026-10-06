<?php

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\Concerns\WritesInAppCopyNow;
use App\Settings\GeneralSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a requester how their booking turned out (or that a reviewer
 * cancelled it), and takes them straight to that booking, where an
 * approved one can be printed.
 */
class BookingDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable, WritesInAppCopyNow;

    /** The outcome as it was decided, kept even if the booking changes later. */
    private readonly BookingStatus $outcome;

    public function __construct(
        public readonly Booking $booking,
    ) {
        $this->outcome = $booking->status;
    }

    public function key(): string
    {
        return match ($this->outcome) {
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

    private function title(): string
    {
        return match ($this->outcome) {
            BookingStatus::Approved => 'Booking approved',
            BookingStatus::Rejected => 'Booking rejected',
            default => 'Booking cancelled by a reviewer',
        };
    }

    /** "Mezzanine Board Room · Thu, Oct 22 · 09:00–11:00" */
    private function slot(): string
    {
        $tz = app(GeneralSettings::class)->timezone;
        $start = $this->booking->starts_at->copy()->timezone($tz);
        $end = $this->booking->ends_at->copy()->timezone($tz);

        $when = $start->isSameDay($end)
            ? $start->format('D, M j').' · '.$start->format('H:i').'–'.$end->format('H:i')
            : $start->format('D, M j, H:i').' – '.$end->format('D, M j, H:i');

        return ($this->booking->resource?->name ?? 'Your booking').' · '.$when;
    }

    private function summary(): string
    {
        $reason = match ($this->outcome) {
            BookingStatus::Rejected => $this->booking->rejection_reason,
            BookingStatus::Cancelled => $this->booking->cancellation_reason,
            default => null,
        };

        return $this->slot().($reason ? ". Reason: {$reason}" : '');
    }

    private function bookingPath(): string
    {
        return route('bookings.show', $this->booking, absolute: false);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->title()}: {$this->slot()}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->title()} ({$this->booking->reference()}).")
            ->line($this->summary());

        if ($this->outcome === BookingStatus::Approved) {
            $mail->line('Open the booking to print its form for everyone to sign on the day.');
        }

        return $mail->action('Open the booking', url($this->bookingPath()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => $this->key(),
            'title' => $this->title(),
            'message' => $this->summary(),
            'action_url' => $this->bookingPath(),
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->reference(),
            'resource_type' => $this->booking->resource?->type?->slug,
        ];
    }
}
