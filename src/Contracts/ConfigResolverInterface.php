<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Repositories\ConfigRepository;

/**
 * Contract for resolving maker-checker configuration for models and executables.
 *
 * Supports two drivers:
 * - 'file': Configuration from config/maker-checker.php (default)
 * - 'database': Configuration from maker_checker_configs table
 */
interface ConfigResolverInterface
{
    /**
     * Get the config repository for database operations.
     */
    public function repository(): ConfigRepository;

    /**
     * Check if database driver is enabled.
     */
    public function usesDatabaseDriver(): bool;

    /**
     * Get the approval requirements for a model and action.
     *
     * @param  array<string, mixed>  $payload  The request payload for conditional config matching
     * @return array<string, int>|array{roles?: array<string, int>, users?: array<string>}
     */
    public function getApprovals(
        string $modelClass,
        RequestType $action,
        ?string $executable = null,
        ?int $teamId = null,
        array $payload = []
    ): array;

    /**
     * Get the unique fields for a model and action.
     *
     * @param  array<string, mixed>  $payload  The request payload for conditional config matching
     * @return array<string>
     */
    public function getUniqueFields(
        string $modelClass,
        RequestType $action,
        ?string $executable = null,
        ?int $teamId = null,
        array $payload = []
    ): array;

    /**
     * Check if maker-checker is required for a model and action.
     */
    public function isRequired(string $modelClass, RequestType $action): bool;

    /**
     * Get the description for a model action.
     *
     * @param  array<string, mixed>  $payload
     */
    public function getDescription(string $modelClass, RequestType $action, array $payload): string;

    /**
     * Get the default approval count from config.
     */
    public function getDefaultApprovalCount(): int;
}
