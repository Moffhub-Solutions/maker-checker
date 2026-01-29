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
     * Get all users who can approve the given request (any required role).
     */
    public function getAllApprovers(MakerCheckerRequest $request): Collection
    {
        $requiredApprovals = $request->required_approvals ?? [];

        if (empty($requiredApprovals)) {
            // No specific roles required, get all potential approvers
            return $this->getAllPotentialApprovers($request);
        }

        $approvers = collect();

        foreach (array_keys($requiredApprovals) as $role) {
            $roleApprovers = $this->getApproversForRole($request, $role);
            $approvers = $approvers->merge($roleApprovers);
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
