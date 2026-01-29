<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * Contract for resolving users who can approve requests based on role or identifier.
 *
 * Implement this interface in your application to define how approvers
 * are resolved for notifications. Register your implementation in a
 * service provider:
 *
 * ```php
 * $this->app->bind(ApproverResolver::class, MyApproverResolver::class);
 * ```
 */
interface ApproverResolver
{
    /**
     * Get users who can approve the given request for the specified role.
     *
     * @param  MakerCheckerRequest  $request  The pending request
     * @param  string  $role  The role required for approval
     * @return Collection<int, Model> Collection of user models that can approve
     */
    public function getApproversForRole(MakerCheckerRequest $request, string $role): Collection;

    /**
     * Get all users who can approve the given request (any required role or user).
     *
     * @param  MakerCheckerRequest  $request  The pending request
     * @return Collection<int, Model> Collection of user models that can approve
     */
    public function getAllApprovers(MakerCheckerRequest $request): Collection;

    /**
     * Get users by their identifiers (email or ID).
     *
     * @param  MakerCheckerRequest  $request  The pending request
     * @param  array<string>  $userIdentifiers  Array of user emails or IDs
     * @return Collection<int, Model> Collection of user models
     */
    public function getApproversByIdentifier(MakerCheckerRequest $request, array $userIdentifiers): Collection;

    /**
     * Get a single approver by their identifier (email or ID).
     *
     * @param  string  $identifier  User email or ID
     * @return Model|null The user model or null if not found
     */
    public function getApproverByIdentifier(string $identifier): ?Model;

    /**
     * Check if a user exists by their identifier.
     *
     * @param  string  $identifier  User email or ID
     */
    public function userExists(string $identifier): bool;

    /**
     * Validate that all specified users exist.
     *
     * @param  array<string>  $userIdentifiers  Array of user emails or IDs
     * @return array<string> Array of identifiers that don't exist
     */
    public function validateUsersExist(array $userIdentifiers): array;
}
