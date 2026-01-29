<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Illuminate\Support\Collection;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * Contract for resolving users who can approve requests based on role.
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
     * @return Collection<int, \Illuminate\Database\Eloquent\Model> Collection of user models that can approve
     */
    public function getApproversForRole(MakerCheckerRequest $request, string $role): Collection;

    /**
     * Get all users who can approve the given request (any required role).
     *
     * @param  MakerCheckerRequest  $request  The pending request
     * @return Collection<int, \Illuminate\Database\Eloquent\Model> Collection of user models that can approve
     */
    public function getAllApprovers(MakerCheckerRequest $request): Collection;
}
