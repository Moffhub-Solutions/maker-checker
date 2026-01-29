<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Moffhub\MakerChecker\Enums\RequestType;

/**
 * Contract for models that require maker-checker approval.
 * Implement this interface on your Eloquent models to define
 * per-model approval requirements.
 */
interface MakerCheckerConfigurable
{
    /**
     * Get the required approvals configuration for this model.
     *
     * Return an array keyed by RequestType value with approval requirements.
     * Each action can specify role-based approval counts.
     *
     * Example:
     * ```php
     * return [
     *     'create' => ['admin' => 1, 'manager' => 1],
     *     'update' => ['admin' => 1],
     *     'delete' => ['admin' => 2, 'super_admin' => 1],
     *     'execute' => ['admin' => 1],
     * ];
     * ```
     *
     * Or use a simple count for any role:
     * ```php
     * return [
     *     'create' => 1,  // 1 approval from anyone
     *     'update' => 2,  // 2 approvals from anyone
     *     'delete' => 3,
     * ];
     * ```
     *
     * Return an empty array to use global defaults.
     */
    public static function makerCheckerApprovals(): array;

    /**
     * Get the fields that determine uniqueness for pending requests.
     *
     * Return field names from the payload that should be checked
     * to prevent duplicate pending requests.
     *
     * Example:
     * ```php
     * return [
     *     'create' => ['email', 'phone'],
     *     'update' => ['id'],
     *     'delete' => [],  // empty = check all fields
     * ];
     * ```
     *
     * Return an empty array to use the entire payload for uniqueness.
     */
    public static function makerCheckerUniqueFields(): array;

    /**
     * Determine if this model requires maker-checker for the given action.
     *
     * This allows conditional bypassing of maker-checker for certain actions.
     *
     * Example:
     * ```php
     * public static function requiresMakerChecker(RequestType $action): bool
     * {
     *     // Only require approval for delete operations
     *     return $action === RequestType::DELETE;
     * }
     * ```
     */
    public static function requiresMakerChecker(RequestType $action): bool;

    /**
     * Get a human-readable description for the action.
     *
     * Used for generating default request descriptions.
     *
     * Example:
     * ```php
     * public static function makerCheckerDescription(RequestType $action, array $payload): string
     * {
     *     return match($action) {
     *         RequestType::CREATE => "Create new user: {$payload['email']}",
     *         RequestType::UPDATE => "Update user settings",
     *         RequestType::DELETE => "Delete user account",
     *         default => "User operation",
     *     };
     * }
     * ```
     */
    public static function makerCheckerDescription(RequestType $action, array $payload): string;
}
