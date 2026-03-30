<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Notifications\PendingApprovalNotification;
use Moffhub\MakerChecker\Notifications\RequestApprovedNotification;
use Moffhub\MakerChecker\Notifications\RequestRejectedNotification;

class NotificationService
{
    public function __construct(
        protected ApproverResolver $approverResolver
    ) {}

    /**
     * Notify approvers about a pending request.
     *
     * @param  MakerCheckerRequest  $request  The pending request
     * @param  bool  $sequential  If true, notify roles in order (first role first)
     */
    public function notifyPendingApproval(MakerCheckerRequest $request, bool $sequential = false): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $requiredApprovals = $request->required_approvals;
        if (is_numeric($requiredApprovals) || !is_array($requiredApprovals)) {
            $requiredApprovals = [];
        }

        if (empty($requiredApprovals)) {
            // No specific roles, notify all potential approvers
            $this->notifyAllApprovers($request);

            return;
        }

        // Check format and handle appropriately
        $isNewFormat = isset($requiredApprovals['users']) || isset($requiredApprovals['roles']);

        if ($isNewFormat) {
            // Notify specific users first
            $users = $requiredApprovals['users'] ?? [];
            if (!empty($users)) {
                $this->notifySpecificUsers($request, $users);
            }

            // Then notify roles
            $roles = $requiredApprovals['roles'] ?? [];
            if (!empty($roles)) {
                if ($sequential) {
                    $this->notifySequentially($request, $roles);
                } else {
                    $this->notifyAllRoles($request, $roles);
                }
            }
        } else {
            // Legacy format
            if ($sequential) {
                $this->notifySequentially($request, $requiredApprovals);
            } else {
                $this->notifyAllRoles($request, $requiredApprovals);
            }
        }
    }

    /**
     * Notify specific users about a pending request.
     *
     * @param  array<string>  $userIdentifiers
     */
    protected function notifySpecificUsers(MakerCheckerRequest $request, array $userIdentifiers): void
    {
        $approvers = $this->approverResolver->getApproversByIdentifier($request, $userIdentifiers);

        foreach ($approvers as $approver) {
            $this->sendPendingNotification($approver, $request, 'user');
        }
    }

    /**
     * Notify the maker that their request was approved.
     */
    public function notifyRequestApproved(MakerCheckerRequest $request): void
    {
        if (!$this->isEnabled() || !$this->shouldNotifyMaker()) {
            return;
        }

        $maker = $request->maker;

        if ($this->isNotifiable($maker)) {
            $notificationClass = $this->getApprovedNotificationClass();
            $maker->notify(new $notificationClass($request));
        }
    }

    /**
     * Notify the maker that their request was rejected.
     */
    public function notifyRequestRejected(MakerCheckerRequest $request): void
    {
        if (!$this->isEnabled() || !$this->shouldNotifyMaker()) {
            return;
        }

        $maker = $request->maker;

        if ($this->isNotifiable($maker)) {
            $notificationClass = $this->getRejectedNotificationClass();
            $maker->notify(new $notificationClass($request));
        }
    }

    /**
     * Notify the next role in sequence (for sequential approvals).
     *
     * Call this after a partial approval to notify the next required role.
     */
    public function notifyNextApprovers(MakerCheckerRequest $request): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // First check for pending users
        $pendingUsers = $request->getPendingUsers();
        if (!empty($pendingUsers)) {
            $this->notifySpecificUsers($request, $pendingUsers);

            return;
        }

        // Then check for pending roles
        $pendingRoles = $request->getPendingRoles();

        if (empty($pendingRoles)) {
            return;
        }

        // Get the first pending role
        $nextRole = array_key_first($pendingRoles);
        $approvers = $this->approverResolver->getApproversForRole($request, $nextRole);

        $this->sendPendingNotifications($approvers, $request, $nextRole);
    }

    /**
     * Notify all approvers regardless of role.
     */
    protected function notifyAllApprovers(MakerCheckerRequest $request): void
    {
        $approvers = $this->approverResolver->getAllApprovers($request);
        $this->sendPendingNotifications($approvers, $request);
    }

    /**
     * Notify roles sequentially (only the first pending role).
     *
     * @param  array<string, int>  $requiredApprovals
     */
    protected function notifySequentially(MakerCheckerRequest $request, array $requiredApprovals): void
    {
        $pendingRoles = $request->getPendingRoles();

        if (empty($pendingRoles)) {
            $pendingRoles = $requiredApprovals;
        }

        // Get the first role that still needs approvals
        $firstRole = array_key_first($pendingRoles);

        if ($firstRole) {
            $approvers = $this->approverResolver->getApproversForRole($request, $firstRole);
            $this->sendPendingNotifications($approvers, $request, $firstRole);
        }
    }

    /**
     * Notify all roles at once.
     *
     * @param  array<string, int>  $requiredApprovals
     */
    protected function notifyAllRoles(MakerCheckerRequest $request, array $requiredApprovals): void
    {
        $notified = [];

        foreach (array_keys($requiredApprovals) as $role) {
            $approvers = $this->approverResolver->getApproversForRole($request, $role);

            foreach ($approvers as $approver) {
                $key = $approver->getKey();

                // Avoid notifying the same user multiple times
                if (!isset($notified[$key])) {
                    $this->sendPendingNotification($approver, $request, $role);
                    $notified[$key] = true;
                }
            }
        }
    }

    /**
     * Send pending approval notifications to a collection of approvers.
     *
     * @param  \Illuminate\Support\Collection<int, Model>  $approvers
     */
    protected function sendPendingNotifications($approvers, MakerCheckerRequest $request, ?string $role = null): void
    {
        foreach ($approvers as $approver) {
            $this->sendPendingNotification($approver, $request, $role);
        }
    }

    /**
     * Send a pending approval notification to a single approver.
     */
    protected function sendPendingNotification(Model $approver, MakerCheckerRequest $request, ?string $role = null): void
    {
        if (!$this->isNotifiable($approver)) {
            return;
        }

        $notificationClass = $this->getPendingNotificationClass();
        $approver->notify(new $notificationClass($request, $role));
    }

    /**
     * Check if a model can receive notifications.
     */
    protected function isNotifiable(Model $model): bool
    {
        return method_exists($model, 'notify');
    }

    protected function isEnabled(): bool
    {
        return config('maker-checker.notifications.enabled', false);
    }

    protected function shouldNotifyMaker(): bool
    {
        return config('maker-checker.notifications.notify_maker', true);
    }

    /**
     * @return class-string<PendingApprovalNotification>
     */
    protected function getPendingNotificationClass(): string
    {
        return config(
            'maker-checker.notifications.pending_notification',
            PendingApprovalNotification::class
        );
    }

    /**
     * @return class-string<RequestApprovedNotification>
     */
    protected function getApprovedNotificationClass(): string
    {
        return config(
            'maker-checker.notifications.approved_notification',
            RequestApprovedNotification::class
        );
    }

    /**
     * @return class-string<RequestRejectedNotification>
     */
    protected function getRejectedNotificationClass(): string
    {
        return config(
            'maker-checker.notifications.rejected_notification',
            RequestRejectedNotification::class
        );
    }
}
