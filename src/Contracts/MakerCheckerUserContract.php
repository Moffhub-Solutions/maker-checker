<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

/**
 * Contract for user models that participate in the maker-checker workflow.
 * Implement this interface on your User model to enable visibility scoping.
 *
 * Note: This interface is designed to be implemented by Eloquent models,
 * which already have getKey() and getMorphClass() methods.
 */
interface MakerCheckerUserContract
{
    /**
     * Check if the user has a specific permission.
     */
    public function hasMakerCheckerPermission(string $permission): bool;

    /**
     * Get the team/company ID for multi-tenant scoping.
     * Return null if not using multi-tenancy.
     */
    public function getMakerCheckerTeamId(): ?int;

    /**
     * Get the user's role for approval purposes.
     * Return null if not using role-based approvals.
     */
    public function getMakerCheckerRole(): ?string;

    /**
     * Get the user's email address.
     */
    public function getMakerCheckerEmail(): ?string;
}
