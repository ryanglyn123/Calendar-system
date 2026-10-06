<?php

namespace App\Notifications;

use App\Models\BookingEditRequest;
use App\Models\User;
use App\Notifications\Concerns\WritesInAppCopyNow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EditRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable, WritesInAppCopyNow;

    public const KEY = 'edit-request.submitted';

    public function __construct(
        public readonly BookingEditRequest $editRequest,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(self::KEY);
    }

    private function summary(): string
    {
        return sprintf(
            '%s proposed a change to their %s booking.',
            $this->editRequest->requester?->name,
            $this->editRequest->booking?->resource?->name,
        );
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Booking change needs review')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary())
            ->action('Review change requests', route('admin.edit-requests.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'key' => self::KEY,
            'title' => 'Booking change needs review',
            'message' => $this->summary(),
            'action_url' => route('admin.edit-requests.index', absolute: false),
            'edit_request_id' => $this->editRequest->id,
        ];
    }
}
