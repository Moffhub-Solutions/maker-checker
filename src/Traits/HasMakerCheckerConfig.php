<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Traits;

use Moffhub\MakerChecker\Enums\RequestType;

/**
 * Trait that provides default implementations for MakerCheckerConfigurable.
 *
 * Use this trait on your Eloquent models and override the properties/methods as needed.
 *
 * @example
 * ```php
 * class User extends Model implements MakerCheckerConfigurable
 * {
 *     use HasMakerCheckerConfig;
 *
 *     // Override default approvals
 *     protected static array $makerCheckerApprovals = [
 *         'create' => ['hr' => 1, 'admin' => 1],
 *         'update' => ['admin' => 1],
 *         'delete' => ['admin' => 2],
 *     ];
 *
 *     // Override unique fields
 *     protected static array $makerCheckerUniqueFields = [
 *         'create' => ['email'],
 *     ];
 *
 *     // Only require maker-checker for delete
 *     protected static array $makerCheckerRequiredFor = ['delete'];
 * }
 * ```
 */
trait HasMakerCheckerConfig
{
    /**
     * Override this property to set approval requirements per action.
     *
     * @var array<string, array<string, int>|int>
     */
    protected static array $makerCheckerApprovals = [];

    /**
     * Override this property to set unique fields per action.
     *
     * @var array<string, array<string>>
     */
    protected static array $makerCheckerUniqueFields = [];

    /**
     * Override this property to specify which actions require maker-checker.
     * Empty array means all actions require maker-checker.
     *
     * @var array<string>
     */
    protected static array $makerCheckerRequiredFor = [];

    /**
     * Get the required approvals configuration for this model.
     */
    public static function makerCheckerApprovals(): array
    {
        return static::$makerCheckerApprovals;
    }

    /**
     * Get the fields that determine uniqueness for pending requests.
     */
    public static function makerCheckerUniqueFields(): array
    {
        return static::$makerCheckerUniqueFields;
    }

    /**
     * Determine if this model requires maker-checker for the given action.
     */
    public static function requiresMakerChecker(RequestType $action): bool
    {
        // If no specific actions defined, require for all
        if (static::$makerCheckerRequiredFor === []) {
            return true;
        }

        return in_array($action->value, static::$makerCheckerRequiredFor, true);
    }

    /**
     * Get a human-readable description for the action.
     */
    public static function makerCheckerDescription(RequestType $action, array $payload): string
    {
        $modelName = class_basename(static::class);

        return match ($action) {
            RequestType::CREATE => "Create new {$modelName}",
            RequestType::UPDATE => "Update {$modelName}",
            RequestType::DELETE => "Delete {$modelName}",
            RequestType::EXECUTE => "Execute action on {$modelName}",
            RequestType::RELATION => "Change {$modelName} relationship",
        };
    }

    /**
     * Get approval requirements for a specific action.
     *
     * @return array<string, int>|int|null
     */
    public static function getApprovalsForAction(RequestType $action): array|int|null
    {
        $approvals = static::makerCheckerApprovals();

        return $approvals[$action->value] ?? null;
    }

    /**
     * Get unique fields for a specific action.
     *
     * @return array<string>
     */
    public static function getUniqueFieldsForAction(RequestType $action): array
    {
        $uniqueFields = static::makerCheckerUniqueFields();

        return $uniqueFields[$action->value] ?? [];
    }
}
