<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * Default implementation of ApproverResolver.
 *
 * This resolver uses a configurable user model and role attribute to find approvers.
 * For more complex scenarios (e.g., Spatie permissions, team-based roles),
 * implement your own ApproverResolver.
 */
class DefaultApproverResolver implements ApproverResolver
{
    /**
     * Get users who can approve the given request for the specified role.
     */
    public function getApproversForRole(MakerCheckerRequest $request, string $role): Collection
    {
        $userModel = $this->getUserModel();
        $roleAttribute = $this->getRoleAttribute();

        if (!$userModel || !class_exists($userModel)) {
            return collect();
        }

        $query = $userModel::query();

        // Exclude the maker from approvers
        $query->where(function ($q) use ($request) {
            $q->where('id', '!=', $request->maker_id)
                ->orWhere(fn($q2) => $q2->whereNull('id'));
        });

        // Filter by role
        if ($roleAttribute) {
            // Support JSON column for roles array
            if ($this->isJsonRoleAttribute()) {
                $query->whereJsonContains($roleAttribute, $role);
            } else {
                $query->where($roleAttribute, $role);
            }
        }

        // Filter by team if enabled and request has team_id
        if ($this->isTeamScopingEnabled() && $request->team_id) {
            $teamAttribute = $this->getTeamAttribute();
            $query->where($teamAttribute, $request->team_id);
        }

        return $query->get();
    }

    /**
     * Get users by their identifiers (email or ID).
     *
     * @param  array<string>  $userIdentifiers  Array of user emails or IDs
     */
    public function getApproversByIdentifier(MakerCheckerRequest $request, array $userIdentifiers): Collection
    {
        $userModel = $this->getUserModel();

        if (!$userModel || !class_exists($userModel) || empty($userIdentifiers)) {
            return collect();
        }

        $query = $userModel::query();

        // Exclude the maker from approvers
        $query->where('id', '!=', $request->maker_id);

        // Filter by identifiers - support both email and ID
        $query->where(function ($q) use ($userIdentifiers) {
            // Check if identifiers look like emails
            $emails = array_filter($userIdentifiers, fn($id) => filter_var($id, FILTER_VALIDATE_EMAIL));
            $ids = array_filter($userIdentifiers, fn($id) => !filter_var($id, FILTER_VALIDATE_EMAIL) && is_numeric($id));

            if (!empty($emails)) {
                $q->orWhereIn('email', $emails);
            }

            if (!empty($ids)) {
                $q->orWhereIn('id', $ids);
            }
        });

        // Filter by team if enabled and request has team_id
        if ($this->isTeamScopingEnabled() && $request->team_id) {
            $teamAttribute = $this->getTeamAttribute();
            $query->where($teamAttribute, $request->team_id);
        }

        return $query->get();
    }

    /**
     * Get a single approver by their identifier (email or ID).
     *
     * Returns null if the user doesn't exist.
     */
    public function getApproverByIdentifier(string $identifier): ?Model
    {
        $userModel = $this->getUserModel();

        if (!$userModel || !class_exists($userModel)) {
            return null;
        }

        $query = $userModel::query();

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $query->where('email', $identifier);
        } elseif (is_numeric($identifier)) {
            $query->where('id', (int) $identifier);
        } else {
            // Try email lookup as fallback for non-email/non-numeric strings
            $query->where('email', $identifier);
        }

        return $query->first();
    }

    /**
     * Check if a user exists by their identifier.
     */
    public function userExists(string $identifier): bool
    {
        return $this->getApproverByIdentifier($identifier) !== null;
    }

    /**
     * Validate that all specified users exist.
     *
     * @param  array<string>  $userIdentifiers
     * @return array<string> Array of identifiers that don't exist
     */
    public function validateUsersExist(array $userIdentifiers): array
    {
        $missingUsers = [];

        foreach ($userIdentifiers as $identifier) {
            if (!$this->userExists($identifier)) {
                $missingUsers[] = $identifier;
            }
        }

        return $missingUsers;
    }

    /**
     * Get all users who can approve the given request (any required role).
     */
    public function getAllApprovers(MakerCheckerRequest $request): Collection
    {
        $requiredApprovals = $request->required_approvals;
        if (is_numeric($requiredApprovals) || !is_array($requiredApprovals)) {
            $requiredApprovals = [];
        }

        if (empty($requiredApprovals)) {
            // No specific roles required, get all potential approvers
            return $this->getAllPotentialApprovers($request);
        }

        $approvers = collect();

        // Check for new format with roles and users
        $isNewFormat = isset($requiredApprovals['users']) || isset($requiredApprovals['roles']);

        if ($isNewFormat) {
            // Get role-based approvers
            $roles = $requiredApprovals['roles'] ?? [];
            foreach (array_keys($roles) as $role) {
                $roleApprovers = $this->getApproversForRole($request, $role);
                $approvers = $approvers->merge($roleApprovers);
            }

            // Get user-specific approvers
            $users = $requiredApprovals['users'] ?? [];
            if (!empty($users)) {
                $userApprovers = $this->getApproversByIdentifier($request, $users);
                $approvers = $approvers->merge($userApprovers);
            }
        } else {
            // Legacy format: ['role' => count]
            foreach (array_keys($requiredApprovals) as $role) {
                $roleApprovers = $this->getApproversForRole($request, $role);
                $approvers = $approvers->merge($roleApprovers);
            }
        }

        // Remove duplicates (same user with multiple roles)
        return $approvers->unique(fn(Model $user) => $user->getKey());
    }

    /**
     * Get all potential approvers without role filtering.
     */
    protected function getAllPotentialApprovers(MakerCheckerRequest $request): Collection
    {
        $userModel = $this->getUserModel();

        if (!$userModel || !class_exists($userModel)) {
            return collect();
        }

        $query = $userModel::query();

        // Exclude the maker
        $query->where('id', '!=', $request->maker_id);

        // Filter by team if enabled
        if ($this->isTeamScopingEnabled() && $request->team_id) {
            $teamAttribute = $this->getTeamAttribute();
            $query->where($teamAttribute, $request->team_id);
        }

        return $query->get();
    }

    protected function getUserModel(): ?string
    {
        return config('maker-checker.notifications.user_model', config('auth.providers.users.model'));
    }

    protected function getRoleAttribute(): ?string
    {
        return config('maker-checker.notifications.role_attribute', 'role');
    }

    protected function isJsonRoleAttribute(): bool
    {
        return config('maker-checker.notifications.role_attribute_is_json', false);
    }

    protected function isTeamScopingEnabled(): bool
    {
        return config('maker-checker.notifications.team_scoping', false);
    }

    protected function getTeamAttribute(): string
    {
        return config('maker-checker.notifications.team_attribute', 'team_id');
    }
}
