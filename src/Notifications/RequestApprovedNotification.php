<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MakerCheckerRequest $request
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = config('maker-checker.notifications.channels', ['mail', 'database']);

        return array_filter($channels, function ($channel) use ($notifiable) {
            if ($channel === 'mail') {
                return method_exists($notifiable, 'routeNotificationForMail')
                    || isset($notifiable->email);
            }

            return true;
        });
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name', 'Application');

        return (new MailMessage)
            ->subject("[{$appName}] Your Request Has Been Approved")
            ->greeting('Good news!')
            ->line('Your request has been approved and executed.')
            ->line("**Request:** {$this->request->description}")
            ->line("**Type:** {$this->request->type->value}")
            ->line("**Request Code:** {$this->request->code}")
            ->line('The requested action has been completed successfully.');
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'request_approved',
            'request_id' => $this->request->id,
            'request_code' => $this->request->code,
            'request_type' => $this->request->type->value,
            'description' => $this->request->description,
            'approved_at' => $this->request->checked_at?->toIso8601String(),
        ];
    }
}
