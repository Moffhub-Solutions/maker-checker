<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Traits;

use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * Trait for models that can approve or reject maker-checker requests.
 *
 * This trait provides default implementations for MakerCheckerUserContract.
 * Use this trait on your User/Admin models to enable approval/rejection capabilities.
 *
 * @mixin Model
 *
 * @example
 * ```php
 * class User extends Model implements MakerCheckerUserContract
 * {
 *     use ChecksRequests;
 *
 *     // Override any methods as needed:
 *     public function getMakerCheckerRole(): ?string
 *     {
 *         return $this->role->value;
 *     }
 *
 *     public function getMakerCheckerTeamId(): ?int
 *     {
 *         return $this->company_id;
 *     }
 * }
 *
 * // Usage:
 * $user->approve($request, 'admin', 'Looks good');
 * $user->reject($request, 'Missing documentation');
 * $user->cancel($request, 'Changed my mind');
 * ```
 */
trait ChecksRequests
{
    /**
     * Approve a maker-checker request.
     *
     * @param  MakerCheckerRequest  $request  The request to approve
     * @param  string|null  $role  The role under which the approval is being made.
     *                             If null, will try to get from getMakerCheckerRole() method.
     * @param  string|null  $remarks  Optional remarks/comments for the approval
     */
    public function approve(MakerCheckerRequest $request, ?string $role = null, ?string $remarks = null): MakerCheckerRequest
    {
        $role ??= $this->getMakerCheckerRole();

        return MakerChecker::approve($request, $this, $role, $remarks);
    }

    /**
     * Reject a maker-checker request.
     *
     * @param  MakerCheckerRequest  $request  The request to reject
     * @param  string|null  $remarks  Optional remarks/comments explaining the rejection
     */
    public function reject(MakerCheckerRequest $request, ?string $remarks = null): MakerCheckerRequest
    {
        return MakerChecker::reject($request, $this, $remarks);
    }

    /**
     * Cancel a maker-checker request (only if this user is the maker).
     *
     * @param  MakerCheckerRequest  $request  The request to cancel
     * @param  string|null  $remarks  Optional remarks/comments explaining the cancellation
     */
    public function cancel(MakerCheckerRequest $request, ?string $remarks = null): MakerCheckerRequest
    {
        return MakerChecker::cancel($request, $this, $remarks);
    }

    // =========================================================================
    // MakerCheckerUserContract implementations
    // Override these methods in your model as needed
    // =========================================================================

    /**
     * Check if the user has a specific maker-checker permission.
     *
     * Override this method to integrate with your permission system.
     * Default implementation checks for common permission methods.
     */
    public function hasMakerCheckerPermission(string $permission): bool
    {
        // Check for Laravel's Gate/Policy
        if (method_exists($this, 'can')) {
            return $this->can($permission);
        }

        // Check for Spatie Permission package
        if (method_exists($this, 'hasPermissionTo')) {
            return $this->hasPermissionTo($permission);
        }

        // Check for generic hasPermission method
        if (method_exists($this, 'hasPermission')) {
            return $this->hasPermission($permission);
        }

        // Default: no permission
        return false;
    }

    /**
     * Get the team/company ID for multi-tenant scoping.
     *
     * Override this method if using multi-tenancy.
     * Default implementation checks common property names.
     */
    public function getMakerCheckerTeamId(): ?int
    {
        // Check common property names
        foreach (['team_id', 'company_id', 'organization_id', 'tenant_id'] as $property) {
            $value = $this->getAttribute($property);
            if ($value !== null) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Get the user's role for approval purposes.
     *
     * Override this method to return the appropriate role string.
     * Default implementation checks common role patterns.
     */
    public function getMakerCheckerRole(): ?string
    {
        // Check for role property/attribute
        $role = $this->getAttribute('role');
        if ($role !== null) {
            // Handle BackedEnum
            if ($role instanceof \BackedEnum) {
                return (string) $role->value;
            }
            // Handle UnitEnum
            if ($role instanceof \UnitEnum) {
                return $role->name;
            }
            // Handle enum roles with value property (PHP 8.1+)
            if (is_object($role) && property_exists($role, 'value')) {
                return (string) $role->value;
            }
            // Handle string roles
            if (is_string($role)) {
                return $role;
            }
        }

        // Check for role_name property
        $roleName = $this->getAttribute('role_name');
        if (is_string($roleName)) {
            return $roleName;
        }

        // Check for roles relationship (many-to-many)
        if (method_exists($this, 'roles') && method_exists($this, 'relationLoaded') && $this->relationLoaded('roles')) {
            /** @var \Illuminate\Database\Eloquent\Collection|null $roles */
            $roles = $this->getAttribute('roles');
            if ($roles !== null) {
                $firstRole = $roles->first();
                if ($firstRole) {
                    return $firstRole->name ?? $firstRole->slug ?? null;
                }
            }
        }

        // Check for Spatie Permission package
        if (method_exists($this, 'getRoleNames')) {
            /** @var \Illuminate\Support\Collection $roles */
            $roles = $this->getRoleNames();
            if ($roles->isNotEmpty()) {
                return $roles->first();
            }
        }

        return null;
    }

    /**
     * Get the user's email address.
     *
     * Override this method if your email is stored differently.
     * Default implementation returns the email attribute.
     */
    public function getMakerCheckerEmail(): ?string
    {
        $email = $this->getAttribute('email');
        if (is_string($email)) {
            return $email;
        }

        // Check for email_address property
        $emailAddress = $this->getAttribute('email_address');
        if (is_string($emailAddress)) {
            return $emailAddress;
        }

        return null;
    }
}
