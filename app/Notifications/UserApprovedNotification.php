<?php

namespace App\Notifications;

use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KEY = 'user.approved';

    public function __construct(
        public readonly User $approver,
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
        $calendar = app(GeneralSettings::class)->organization_acronym.' Calendar';

        return (new MailMessage)
            ->subject("Your {$calendar} account has been approved")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your account has been approved. You can now sign in and start using the {$calendar}.")
            ->action('Open the calendar', route('dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => 'Account approved',
            'message' => 'Your account has been approved. Welcome to the '.app(GeneralSettings::class)->organization_acronym.' Calendar!',
            'action_url' => route('dashboard', absolute: false),
        ];
    }
}
