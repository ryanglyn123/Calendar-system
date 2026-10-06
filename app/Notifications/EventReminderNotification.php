<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KEY = 'event.reminder';

    public function __construct(
        public readonly Event $event,
        public readonly CarbonInterface $occurrenceStartsAt,
        public readonly string $channel,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        // The reminder row picked its channel; the user's notification
        // settings can still switch that channel off.
        return array_values(array_intersect(
            [$this->channel],
            $notifiable->notificationChannelsFor(self::KEY),
        ));
    }

    private function when(User $notifiable): string
    {
        return $this->occurrenceStartsAt
            ->avoidMutation()
            ->timezone($notifiable->timezone ?: app(GeneralSettings::class)->timezone)
            ->format('l, F j \a\t H:i');
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reminder: {$this->event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("\"{$this->event->title}\" starts {$this->when($notifiable)}.")
            ->lineIf((bool) $this->event->location, "Location: {$this->event->location}")
            ->action('Open the calendar', route('dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => 'Upcoming event',
            'message' => "\"{$this->event->title}\" starts {$this->when($notifiable)}.",
            'action_url' => route('dashboard', absolute: false),
            'event_id' => $this->event->id,
        ];
    }
}
