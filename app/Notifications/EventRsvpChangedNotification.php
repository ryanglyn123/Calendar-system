<?php

namespace App\Notifications;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventRsvpChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KEY = 'event.rsvp-changed';

    public function __construct(
        public readonly Event $event,
        public readonly User $attendee,
        public readonly RsvpStatus $status,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(self::KEY);
    }

    private function verb(): string
    {
        return match ($this->status) {
            RsvpStatus::Accepted => 'accepted',
            RsvpStatus::Declined => 'declined',
            RsvpStatus::Tentative => 'tentatively accepted',
            RsvpStatus::Pending => 'reset their response to',
        };
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("RSVP update: {$this->event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->attendee->name} {$this->verb()} your invitation to \"{$this->event->title}\".")
            ->action('Open the calendar', route('dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => 'RSVP update',
            'message' => "{$this->attendee->name} {$this->verb()} \"{$this->event->title}\".",
            'action_url' => route('dashboard', absolute: false),
            'event_id' => $this->event->id,
        ];
    }
}
