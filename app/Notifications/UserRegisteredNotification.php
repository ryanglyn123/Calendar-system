<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KEY = 'user.registered';

    public function __construct(
        public readonly User $registrant,
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
            ->subject('New registration awaiting approval')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->registrant->name} ({$this->registrant->email}) has registered and is awaiting approval.")
            ->action('Review pending registrations', route('admin.pending-registrations.index'))
            ->line('They will not be able to use the calendar until approved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => 'New registration awaiting approval',
            'message' => "{$this->registrant->name} ({$this->registrant->email}) is awaiting approval.",
            'action_url' => route('admin.pending-registrations.index', absolute: false),
            'user_id' => $this->registrant->id,
        ];
    }
}
