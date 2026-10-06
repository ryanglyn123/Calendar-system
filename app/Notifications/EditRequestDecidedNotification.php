<?php

namespace App\Notifications;

use App\Enums\EditRequestStatus;
use App\Models\BookingEditRequest;
use App\Models\User;
use App\Notifications\Concerns\WritesInAppCopyNow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EditRequestDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable, WritesInAppCopyNow;

    public function __construct(
        public readonly BookingEditRequest $editRequest,
    ) {}

    public function key(): string
    {
        return $this->editRequest->status === EditRequestStatus::Applied
            ? 'edit-request.applied'
            : 'edit-request.rejected';
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
        $resource = $this->editRequest->booking?->resource?->name;

        return $this->editRequest->status === EditRequestStatus::Applied
            ? "Your change to the {$resource} booking was applied."
            : "Your change to the {$resource} booking was rejected: {$this->editRequest->rejection_reason}";
    }

    /** The booking itself, now carrying the change if it was applied. */
    private function bookingPath(): string
    {
        return $this->editRequest->booking === null
            ? route('dashboard', absolute: false)
            : route('bookings.show', $this->editRequest->booking, absolute: false);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Booking change '.$this->editRequest->status->value)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary())
            ->action('Open the booking', url($this->bookingPath()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => $this->key(),
            'title' => 'Booking change '.$this->editRequest->status->value,
            'message' => $this->summary(),
            'action_url' => $this->bookingPath(),
            'edit_request_id' => $this->editRequest->id,
            'booking_id' => $this->editRequest->booking_id,
        ];
    }
}
