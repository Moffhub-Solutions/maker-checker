<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class ApprovalReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MakerCheckerRequest $request,
        public bool $isEscalation = false,
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
        $subject = $this->isEscalation
            ? "[{$appName}] ESCALATION: Approval Request Overdue"
            : "[{$appName}] Reminder: Pending Approval Request";

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello!');

        if ($this->isEscalation) {
            $mail->line('An approval request has been pending beyond the escalation threshold and requires immediate attention.');
        } else {
            $mail->line('A reminder that the following request is still awaiting your approval.');
        }

        $mail->line("**Request:** {$this->request->description}")
            ->line("**Type:** {$this->request->type->value}")
            ->line("**Request Code:** {$this->request->code}")
            ->line("**Created:** {$this->request->created_at->toDateTimeString()}");

        $actionUrl = $this->getActionUrl();
        if ($actionUrl) {
            $mail->action('Review Request', $actionUrl);
        }

        return $mail->line('Please review and take appropriate action as soon as possible.');
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->isEscalation ? 'approval_escalation' : 'approval_reminder',
            'request_id' => $this->request->id,
            'request_code' => $this->request->code,
            'request_type' => $this->request->type->value,
            'description' => $this->request->description,
            'created_at' => $this->request->created_at?->toIso8601String(),
        ];
    }

    /**
     * Get the URL to view/act on the request.
     */
    protected function getActionUrl(): ?string
    {
        $baseUrl = config('maker-checker.notifications.action_url');

        if ($baseUrl) {
            return str_replace(
                ['{id}', '{code}'],
                [(string) $this->request->id, $this->request->code],
                (string) $baseUrl
            );
        }

        return null;
    }
}
