<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventInvitedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KEY = 'event.invited';

    public function __construct(
        public readonly Event $event,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(self::KEY);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Invitation: {$this->event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->event->creator?->name} invited you to \"{$this->event->title}\".")
            ->line($this->event->starts_at->timezone($notifiable->timezone ?: app(GeneralSettings::class)->timezone)->format('l, F j, Y \a\t H:i'))
            ->action('Open the calendar', route('dashboard'))
            ->line('Respond from the event details in the calendar.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => 'Event invitation',
            'message' => "{$this->event->creator?->name} invited you to \"{$this->event->title}\".",
            'action_url' => route('dashboard', absolute: false),
            'event_id' => $this->event->id,
        ];
    }
}
