<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KEY = 'user.invited';

    public function __construct(
        public readonly Invitation $invitation,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You are invited to the FDCP Calendar')
            ->greeting('Hello,')
            ->line("{$this->invitation->inviter->name} has invited you to join the FDCP Calendar.")
            ->action('Accept invitation', route('invitation.show', $this->invitation->token))
            ->line("This invitation expires {$this->invitation->expires_at->toFormattedDateString()}.");
    }
}
