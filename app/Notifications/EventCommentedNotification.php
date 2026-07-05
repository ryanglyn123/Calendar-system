<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventCommentedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KEY = 'event.commented';

    public function __construct(
        public readonly Event $event,
        public readonly User $commenter,
        public readonly string $excerpt,
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
            ->subject("New comment: {$this->event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->commenter->name} commented on \"{$this->event->title}\": {$this->excerpt}")
            ->action('Open the calendar', route('dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => 'New comment',
            'message' => "{$this->commenter->name} commented on \"{$this->event->title}\": {$this->excerpt}",
            'action_url' => route('dashboard', absolute: false),
            'event_id' => $this->event->id,
        ];
    }
}
