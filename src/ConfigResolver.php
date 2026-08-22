<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker;

use Moffhub\MakerChecker\Contracts\ConfigResolverInterface;
use Moffhub\MakerChecker\Contracts\MakerCheckerConfigurable;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerConfig;
use Moffhub\MakerChecker\Repositories\ConfigRepository;

/**
 * Resolves maker-checker configuration for models and executables.
 *
 * Supports two drivers:
 * - 'file': Configuration from config/maker-checker.php (default)
 * - 'database': Configuration from maker_checker_configs table
 *
 * Configuration priority (highest to lowest):
 * 1. Explicit parameters passed to RequestBuilder
 * 2. Model implementing MakerCheckerConfigurable interface
 * 3. Database config (if driver is 'database')
 * 4. Config file model-specific settings
 * 5. Config file executable-specific settings (for execute type)
 * 6. Config file global_approvals
 * 7. Config file default_approval_count
 */
class ConfigResolver implements ConfigResolverInterface
{
    private ?ConfigRepository $repository = null;

    private readonly string $driver;

    public function __construct(private array $config)
    {
        $this->driver = $this->config['config_driver'] ?? 'file';
    }

    /**
     * Get the config repository for database operations.
     */
    public function repository(): ConfigRepository
    {
        if (!$this->repository instanceof ConfigRepository) {
            $this->repository = new ConfigRepository;
        }

        return $this->repository;
    }

    /**
     * Check if database driver is enabled.
     */
    public function usesDatabaseDriver(): bool
    {
        return $this->driver === 'database';
    }

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
    ): array {
        // 1. Check if model implements MakerCheckerConfigurable
        if ($this->implementsConfigurable($modelClass)) {
            /** @var class-string<MakerCheckerConfigurable> $modelClass */
            $approvals = $modelClass::makerCheckerApprovals();
            if (!empty($approvals) && isset($approvals[$action->value])) {
                return $this->normalizeApprovals($approvals[$action->value]);
            }
        }

        // 2. Check database config (if driver is 'database')
        if ($this->usesDatabaseDriver()) {
            $dbConfig = $this->getFromDatabase($modelClass, $action, $executable, $teamId, $payload);
            if ($dbConfig && $dbConfig->getApprovals() !== []) {
                return $dbConfig->getApprovals();
            }
        }

        // 3. Check config file model-specific settings
        $modelConfig = $this->config['models'][$modelClass] ?? [];
        if (!empty($modelConfig['approvals'][$action->value])) {
            return $this->normalizeApprovals($modelConfig['approvals'][$action->value]);
        }

        // 4. Check executable-specific settings (for execute type)
        if ($action === RequestType::EXECUTE && $executable) {
            $executableConfig = $this->config['executables'][$executable] ?? [];
            if (!empty($executableConfig['approvals'])) {
                return $this->normalizeApprovals($executableConfig['approvals']);
            }
        }

        // 5. Check global_approvals
        $globalApprovals = $this->config['global_approvals'][$action->value] ?? [];
        if (!empty($globalApprovals)) {
            return $this->normalizeApprovals($globalApprovals);
        }

        // 6. Return empty array (will use default_approval_count)
        return [];
    }

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
    ): array {
        // 1. Check if model implements MakerCheckerConfigurable
        if ($this->implementsConfigurable($modelClass)) {
            /** @var class-string<MakerCheckerConfigurable> $modelClass */
            $uniqueFields = $modelClass::makerCheckerUniqueFields();
            if (!empty($uniqueFields) && isset($uniqueFields[$action->value])) {
                return $uniqueFields[$action->value];
            }
        }

        // 2. Check database config (if driver is 'database')
        if ($this->usesDatabaseDriver()) {
            $dbConfig = $this->getFromDatabase($modelClass, $action, $executable, $teamId, $payload);
            if ($dbConfig && $dbConfig->getUniqueFields() !== []) {
                return $dbConfig->getUniqueFields();
            }
        }

        // 3. Check config file model-specific settings
        $modelConfig = $this->config['models'][$modelClass] ?? [];
        if (isset($modelConfig['unique_fields'][$action->value])) {
            return $modelConfig['unique_fields'][$action->value];
        }

        // 4. Check executable-specific settings (for execute type)
        if ($action === RequestType::EXECUTE && $executable) {
            $executableConfig = $this->config['executables'][$executable] ?? [];
            if (isset($executableConfig['unique_fields'])) {
                return $executableConfig['unique_fields'];
            }
        }

        return [];
    }

    /**
     * Check if maker-checker is required for a model and action.
     */
    public function isRequired(string $modelClass, RequestType $action): bool
    {
        // 1. Check if model implements MakerCheckerConfigurable
        if ($this->implementsConfigurable($modelClass)) {
            /** @var class-string<MakerCheckerConfigurable> $modelClass */
            return $modelClass::requiresMakerChecker($action);
        }

        // 2. Check config file model-specific settings
        $modelConfig = $this->config['models'][$modelClass] ?? [];
        if (isset($modelConfig['required_for'])) {
            // Empty array means all actions require maker-checker
            if (empty($modelConfig['required_for'])) {
                return true;
            }

            return in_array($action->value, $modelConfig['required_for'], true);
        }

        // Default: required for all actions
        return true;
    }

    /**
     * Get the description for a model action.
     *
     * @param  array<string, mixed>  $payload
     */
    public function getDescription(string $modelClass, RequestType $action, array $payload): string
    {
        // Check if model implements MakerCheckerConfigurable
        if ($this->implementsConfigurable($modelClass)) {
            /** @var class-string<MakerCheckerConfigurable> $modelClass */
            return $modelClass::makerCheckerDescription($action, $payload);
        }

        $modelName = class_basename($modelClass);

        return match ($action) {
            RequestType::CREATE => "Create new {$modelName}",
            RequestType::UPDATE => "Update {$modelName}",
            RequestType::DELETE => "Delete {$modelName}",
            RequestType::EXECUTE => "Execute action on {$modelName}",
            RequestType::RELATION => "Change {$modelName} relationship",
        };
    }

    /**
     * Get the default approval count from config.
     */
    public function getDefaultApprovalCount(): int
    {
        return (int) ($this->config['default_approval_count'] ?? 1);
    }

    /**
     * Get config from database.
     *
     * @param  array<string, mixed>  $payload  The request payload for conditional config matching
     */
    protected function getFromDatabase(
        string $modelClass,
        RequestType $action,
        ?string $executable,
        ?int $teamId,
        array $payload = []
    ): ?MakerCheckerConfig {
        if ($action === RequestType::EXECUTE && $executable) {
            return $this->repository()->getMatchingExecutableConfig($executable, $payload, $teamId);
        }

        return $this->repository()->getMatchingConfig($modelClass, $action, $payload, $teamId);
    }

    /**
     * Check if the model implements MakerCheckerConfigurable.
     */
    private function implementsConfigurable(string $modelClass): bool
    {
        return class_exists($modelClass)
            && in_array(MakerCheckerConfigurable::class, class_implements($modelClass) ?: [], true);
    }

    /**
     * Normalize approval requirements to array format.
     *
     * @param  array<string, int>|int  $approvals
     * @return array<string, int>
     */
    private function normalizeApprovals(array|int $approvals): array
    {
        if (is_int($approvals)) {
            return ['default' => $approvals];
        }

        return $approvals;
    }
}
