<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class PendingApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MakerCheckerRequest $request,
        public ?string $role = null
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = config('maker-checker.notifications.channels', ['mail', 'database']);

        // Filter to only enabled channels
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
        $makerName = $this->getMakerName();
        $actionUrl = $this->getActionUrl();

        $mail = (new MailMessage)
            ->subject("[{$appName}] Pending Approval Request")
            ->greeting('Hello!')
            ->line('A new request is awaiting your approval.')
            ->line("**Request:** {$this->request->description}")
            ->line("**Type:** {$this->request->type->value}")
            ->line("**Submitted by:** {$makerName}")
            ->line("**Request Code:** {$this->request->code}");

        if ($this->role) {
            $mail->line("**Your Role:** {$this->role}");
        }

        if ($actionUrl) {
            $mail->action('Review Request', $actionUrl);
        }

        return $mail->line('Please review and take appropriate action.');
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pending_approval',
            'request_id' => $this->request->id,
            'request_code' => $this->request->code,
            'request_type' => $this->request->type->value,
            'description' => $this->request->description,
            'subject_type' => $this->request->subject_type,
            'subject_id' => $this->request->subject_id,
            'maker_id' => $this->request->maker_id,
            'maker_type' => $this->request->maker_type,
            'role' => $this->role,
            'created_at' => $this->request->created_at?->toIso8601String(),
        ];
    }

    /**
     * Get the maker's name for display.
     */
    protected function getMakerName(): string
    {
        $maker = $this->request->maker;

        // Try common name attributes
        foreach (['name', 'full_name', 'display_name', 'username', 'email'] as $attr) {
            if (isset($maker->{$attr}) && is_string($maker->{$attr})) {
                return $maker->{$attr};
            }
        }

        $key = $maker->getKey();

        return 'User #'.($key !== null ? (string) $key : 'Unknown');
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
