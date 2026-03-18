<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * Audit service for logging maker-checker approval actions.
 *
 * Supports two storage drivers:
 * - 'database': Writes to the maker_checker_audit_logs table
 * - 'log': Writes to a Laravel log channel
 */
class AuditService
{
    /**
     * Log an audit action.
     *
     * @param  string  $action  The action performed (e.g., 'approved', 'rejected', 'cancelled')
     * @param  MakerCheckerRequest  $request  The maker-checker request
     * @param  string  $actorType  The morph class of the actor
     * @param  int|string  $actorId  The ID of the actor
     * @param  string  $previousStatus  The status before the action
     * @param  string  $newStatus  The status after the action
     * @param  array<string, mixed>  $metadata  Additional context data
     */
    public function log(
        string $action,
        MakerCheckerRequest $request,
        string $actorType,
        int|string $actorId,
        string $previousStatus,
        string $newStatus,
        array $metadata = [],
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $driver = $this->getDriver();

        $data = [
            'request_id' => $request->getKey(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'ip_address' => request()->ip(),
            'metadata' => $metadata,
        ];

        match ($driver) {
            'database' => $this->logToDatabase($data),
            'log' => $this->logToChannel($data),
            default => $this->logToDatabase($data),
        };
    }

    /**
     * Write audit entry to the database.
     *
     * @param  array<string, mixed>  $data
     */
    private function logToDatabase(array $data): void
    {
        $tableName = config('maker-checker.audit.table_name', 'maker_checker_audit_logs');

        DB::table($tableName)->insert([
            'request_id' => $data['request_id'],
            'actor_type' => $data['actor_type'],
            'actor_id' => $data['actor_id'],
            'action' => $data['action'],
            'previous_status' => $data['previous_status'],
            'new_status' => $data['new_status'],
            'ip_address' => $data['ip_address'],
            'metadata' => json_encode($data['metadata']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Write audit entry to a log channel.
     *
     * @param  array<string, mixed>  $data
     */
    private function logToChannel(array $data): void
    {
        $channel = config('maker-checker.audit.log_channel');

        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->info('MakerChecker Audit', $data);
    }

    /**
     * Check if audit logging is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('maker-checker.audit.enabled', true);
    }

    /**
     * Get the configured audit driver.
     */
    public function getDriver(): string
    {
        return (string) config('maker-checker.audit.driver', 'database');
    }
}
