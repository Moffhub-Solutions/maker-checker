<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestRejectedNotification extends Notification implements ShouldQueue
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

        $mail = (new MailMessage)
            ->subject("[{$appName}] Your Request Has Been Rejected")
            ->greeting('Hello,')
            ->line('Unfortunately, your request has been rejected.')
            ->line("**Request:** {$this->request->description}")
            ->line("**Type:** {$this->request->type->value}")
            ->line("**Request Code:** {$this->request->code}");

        if ($this->request->remarks) {
            $mail->line("**Reason:** {$this->request->remarks}");
        }

        return $mail->line('Please contact the reviewer if you have questions.');
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'request_rejected',
            'request_id' => $this->request->id,
            'request_code' => $this->request->code,
            'request_type' => $this->request->type->value,
            'description' => $this->request->description,
            'remarks' => $this->request->remarks,
            'rejected_at' => $this->request->checked_at?->toIso8601String(),
        ];
    }
}
