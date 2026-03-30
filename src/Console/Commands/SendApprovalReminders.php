<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\MakerCheckerServiceProvider;
use Moffhub\MakerChecker\Notifications\ApprovalReminderNotification;

class SendApprovalReminders extends Command
{
    protected $signature = 'maker-checker:send-reminders
                            {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Send reminder notifications for pending approval requests and escalate overdue ones.';

    public function handle(): int
    {
        if (!config('maker-checker.reminders.enabled', false)) {
            $this->info('Reminders are disabled. Set maker-checker.reminders.enabled to true.');

            return self::SUCCESS;
        }

        $reminderHours = (float) config('maker-checker.reminders.after_hours', 24);
        $escalationHours = (float) config('maker-checker.escalation.after_hours', 48);

        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();

        $reminderCutoff = now()->subHours($reminderHours);
        $escalationCutoff = now()->subHours($escalationHours);

        // Get pending requests older than the reminder threshold
        $reminderRequests = $requestModel::query()
            ->whereIn('status', [RequestStatus::PENDING, RequestStatus::PARTIALLY_APPROVED])
            ->where('created_at', '<=', $reminderCutoff)
            ->where('created_at', '>', $escalationCutoff)
            ->get();

        // Get pending requests older than the escalation threshold
        $escalationRequests = $requestModel::query()
            ->whereIn('status', [RequestStatus::PENDING, RequestStatus::PARTIALLY_APPROVED])
            ->where('created_at', '<=', $escalationCutoff)
            ->get();

        $resolver = app(ApproverResolver::class);

        $reminderCount = 0;
        $escalationCount = 0;

        // Send reminders
        foreach ($reminderRequests as $request) {
            $approvers = $resolver->getAllApprovers($request);

            if ($this->option('dry-run')) {
                $this->info("Would send reminder for request #{$request->id} ({$request->code}) to {$approvers->count()} approver(s).");
            } else {
                foreach ($approvers as $approver) {
                    if (method_exists($approver, 'notify')) {
                        $approver->notify(new ApprovalReminderNotification($request, false));
                    }
                }
            }

            $reminderCount++;
        }

        // Send escalations
        foreach ($escalationRequests as $request) {
            $approvers = $resolver->getAllApprovers($request);

            if ($this->option('dry-run')) {
                $this->info("Would send escalation for request #{$request->id} ({$request->code}) to {$approvers->count()} approver(s).");
            } else {
                foreach ($approvers as $approver) {
                    if (method_exists($approver, 'notify')) {
                        $approver->notify(new ApprovalReminderNotification($request, true));
                    }
                }
            }

            // Send escalation to configured email addresses
            $escalationEmails = config('maker-checker.escalation.notify', []);
            if (!empty($escalationEmails) && !$this->option('dry-run')) {
                Notification::route('mail', $escalationEmails)
                    ->notify(new ApprovalReminderNotification($request, true));
            } elseif (!empty($escalationEmails) && $this->option('dry-run')) {
                $this->info('Would send escalation email to: '.implode(', ', $escalationEmails));
            }

            $escalationCount++;
        }

        $this->info("Sent {$reminderCount} reminder(s) and {$escalationCount} escalation(s).");

        return self::SUCCESS;
    }
}
