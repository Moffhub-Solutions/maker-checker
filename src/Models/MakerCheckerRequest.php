<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Models;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Moffhub\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Moffhub\MakerChecker\Contracts\MakerCheckerUserContract;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Facades\MakerChecker;

/**
 * Base maker-checker request model.
 *
 * To enable search functionality with Laravel Scout, extend this class and use the Searchable trait:
 *
 * ```php
 * use Laravel\Scout\Searchable;
 *
 * class SearchableMakerCheckerRequest extends MakerCheckerRequest
 * {
 *     use Searchable;
 * }
 * ```
 *
 * Then update your config to use the extended class:
 * ```php
 * 'request_model' => SearchableMakerCheckerRequest::class,
 * ```
 *
 * @property int $id
 * @property string $code
 * @property string $description
 * @property array|null $payload
 * @property array|null $required_approvals
 * @property array|null $approvals
 * @property array|null $metadata
 * @property RequestStatus $status
 * @property RequestType $type
 * @property string $subject_type
 * @property int|null $subject_id
 * @property int|null $team_id
 * @property string $maker_type
 * @property int $maker_id
 * @property string|null $checker_type
 * @property int|null $checker_id
 * @property string|Closure|null $executable
 * @property Carbon|null $checked_at
 * @property Carbon|null $made_at
 * @property string $remarks
 * @property string|null $exception
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Model $subject
 * @property Model $maker
 * @property Model|null $checker
 *
 * @method static static create(array $attributes = [])
 * @method static static firstOrCreate(array $attributes, array $values = [])
 * @method static static firstOrNew(array $attributes, array $values = [])
 * @method static static firstWhere($column, $operator = null, $value = null, $boolean = 'and')
 * @method static static updateOrCreate(array $attributes, array $values = [])
 */
class MakerCheckerRequest extends Model implements MakerCheckerRequestInterface
{
    protected $guarded = ['id', 'code'];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'status' => RequestStatus::class,
        'type' => RequestType::class,
        'made_at' => 'datetime',
        'checked_at' => 'datetime',
        'approvals' => 'array',
        'required_approvals' => 'array',
        'team_id' => 'integer',
    ];

    #[\Override]
    public function getTable(): string
    {
        return config('maker-checker.table_name', 'maker_checker_requests');
    }

    public static function allowedFilters(): array
    {
        return [
            'type',
            'metadata',
            'payload',
            'status',
        ];
    }

    public static function allowedSorts(): array
    {
        return [
            'created_at',
            'type',
            'subject_type',
            'maker_type',
            'status',
        ];
    }

    /**
     * @throws Exception
     */
    public function addApproval(Model $approver, ?string $role = null, ?string $userIdentifier = null): void
    {
        $approvals = $this->approvals ?? [];
        $role = $role ?: 'default';

        // Check if this approver has already approved
        foreach ($approvals as $approval) {
            if ($approval['checker_type'] === $approver->getMorphClass() && $approval['checker_id'] === $approver->getKey()) {
                throw new Exception('This approver has already approved the request.');
            }
        }

        // Get user email if available
        $userEmail = null;
        if (method_exists($approver, 'getMakerCheckerEmail')) {
            $userEmail = $approver->getMakerCheckerEmail();
        } elseif (isset($approver->email)) {
            $userEmail = $approver->email;
        }

        // Add the new approver to the approvals array
        $approvals[] = [
            'checker_type' => $approver->getMorphClass(),
            'checker_id' => $approver->getKey(),
            'role' => $role,
            'user_email' => $userEmail,
            'user_identifier' => $userIdentifier ?? $userEmail,
            'approved_at' => now()->toIso8601String(),
        ];

        $this->update([
            'approvals' => $approvals,
            'checker_type' => $approver->getMorphClass(),
            'checker_id' => $approver->getKey(),
        ]);
    }

    public function hasMetApprovalThreshold(): bool
    {
        $requiredApprovals = $this->required_approvals ?? [];
        /** @var array $actualApprovals */
        $actualApprovals = $this->approvals ?? [];

        if (empty($requiredApprovals)) {
            return count($actualApprovals) >= $this->defaultApprovalCount();
        }

        $mode = $this->getApprovalMode();

        // Check for role-based approvals
        $roles = $requiredApprovals['roles'] ?? $requiredApprovals;
        // If there's a 'users' key, the format is new; otherwise it's legacy format
        $isNewFormat = isset($requiredApprovals['users']) || isset($requiredApprovals['roles']) || isset($requiredApprovals['mode']);

        if (!$isNewFormat) {
            // Legacy format: ['role' => count] — always AND logic
            foreach ($roles as $role => $count) {
                $actualCount = collect($actualApprovals)->where('role', $role)->count();
                if ($actualCount < $count) {
                    return false;
                }
            }
        } elseif ($mode === 'any') {
            // OR mode: threshold is met if ANY role meets its count OR ANY required user has approved
            $roles = $requiredApprovals['roles'] ?? [];
            $requiredUsers = $requiredApprovals['users'] ?? [];

            // Check if any role threshold is met
            foreach ($roles as $role => $count) {
                $actualCount = collect($actualApprovals)->where('role', $role)->count();
                if ($actualCount >= $count) {
                    return true;
                }
            }

            // Check if any required user has approved
            foreach ($requiredUsers as $userIdentifier) {
                if ($this->hasUserApproved($userIdentifier, $actualApprovals)) {
                    return true;
                }
            }

            // None of the OR conditions were met
            return false;
        } else {
            // AND mode (default): all roles must meet thresholds AND all users must approve
            $roles = $requiredApprovals['roles'] ?? [];
            foreach ($roles as $role => $count) {
                $actualCount = collect($actualApprovals)->where('role', $role)->count();
                if ($actualCount < $count) {
                    return false;
                }
            }

            // Check for user-based approvals
            $requiredUsers = $requiredApprovals['users'] ?? [];
            foreach ($requiredUsers as $userIdentifier) {
                if (!$this->hasUserApproved($userIdentifier, $actualApprovals)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function defaultApprovalCount(): int
    {
        return (int) config('maker-checker.default_approval_count', 1);
    }

    /**
     * Get the approval mode for this request.
     *
     * @return string 'all' (AND logic, default) or 'any' (OR logic)
     */
    public function getApprovalMode(): string
    {
        $requiredApprovals = $this->required_approvals ?? [];

        return $requiredApprovals['mode'] ?? 'all';
    }

    /**
     * Check if a specific user has approved this request.
     */
    protected function hasUserApproved(string $userIdentifier, array $actualApprovals): bool
    {
        return (bool) collect($actualApprovals)->first(function ($approval) use ($userIdentifier) {
            if (($approval['user_email'] ?? null) === $userIdentifier) {
                return true;
            }
            if (($approval['user_identifier'] ?? null) === $userIdentifier) {
                return true;
            }
            if (is_numeric($userIdentifier) && (string) ($approval['checker_id'] ?? null) === (string) $userIdentifier) {
                return true;
            }

            return false;
        });
    }

    /**
     * Get roles that still need to approve.
     *
     * In 'any' mode, returns roles that haven't yet met their threshold.
     * Once any single role meets its threshold, the approval is satisfied,
     * so this is informational — showing which roles could still fulfill the requirement.
     *
     * @return array<string, int> Role => remaining count needed
     */
    public function getPendingRoles(): array
    {
        /** @var array $requiredApprovals */
        $requiredApprovals = $this->required_approvals ?? [];
        /** @var array $actualApprovals */
        $actualApprovals = $this->approvals ?? [];

        $pendingRoles = [];

        // Determine format
        $isNewFormat = isset($requiredApprovals['users']) || isset($requiredApprovals['roles']) || isset($requiredApprovals['mode']);
        $roles = $isNewFormat ? ($requiredApprovals['roles'] ?? []) : $requiredApprovals;

        foreach ($roles as $role => $requiredCount) {
            $actualCount = collect($actualApprovals)->where('role', $role)->count();
            if ($actualCount < $requiredCount) {
                $pendingRoles[$role] = $requiredCount - $actualCount;
            }
        }

        return $pendingRoles;
    }

    /**
     * Get the list of users who still need to approve.
     *
     * In 'any' mode, returns users who haven't yet approved.
     * Once any single user approves, the approval may be satisfied,
     * so this is informational — showing which users could still fulfill the requirement.
     *
     * @return array<string>
     */
    public function getPendingUsers(): array
    {
        /** @var array $requiredApprovals */
        $requiredApprovals = $this->required_approvals ?? [];
        /** @var array $actualApprovals */
        $actualApprovals = $this->approvals ?? [];

        // Check if new format with users
        if (!isset($requiredApprovals['users'])) {
            return [];
        }

        $requiredUsers = $requiredApprovals['users'];
        $pendingUsers = [];

        foreach ($requiredUsers as $userIdentifier) {
            if (!$this->hasUserApproved($userIdentifier, $actualApprovals)) {
                $pendingUsers[] = $userIdentifier;
            }
        }

        return $pendingUsers;
    }

    /**
     * Check if the request requires user-specific approvals.
     */
    public function requiresUserApprovals(): bool
    {
        $requiredApprovals = $this->required_approvals ?? [];

        return !empty($requiredApprovals['users']);
    }

    /**
     * Get the total number of approvals received.
     */
    public function getApprovalCount(): int
    {
        return count($this->approvals ?? []);
    }

    /**
     * Get all approvers for this request.
     *
     * @return array<array{checker_type: string, checker_id: mixed, role: string, user_email: string|null, user_identifier: string|null, approved_at: string}>
     */
    public function getApprovers(): array
    {
        return $this->approvals ?? [];
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'description' => $this->description,
            'status' => $this->status,
            'type' => $this->type,
        ];
    }

    /**
     * Scope to filter requests visible to a user.
     *
     * The user can either:
     * 1. Implement MakerCheckerUserContract for full control
     * 2. Have hasMakerCheckerPermission(), getMakerCheckerTeamId() methods
     * 3. Be any model (will only see own requests)
     *
     * @param  EloquentBuilder<static>  $builder
     * @return EloquentBuilder<static>
     */
    public function scopeVisibleTo(EloquentBuilder $builder, Model $user): EloquentBuilder
    {
        $viewAnyPermission = config('maker-checker.view_any_permission');

        // Check if user can view all requests
        if ($viewAnyPermission && $this->userHasPermission($user, $viewAnyPermission)) {
            return $builder;
        }

        // Check for team-based visibility
        $teamId = $this->getUserTeamId($user);
        if ($teamId !== null) {
            return $builder->where('team_id', '=', $teamId);
        }

        // Default: only see own requests
        return $builder->where('maker_id', '=', $user->getKey())
            ->where('maker_type', '=', $user->getMorphClass());
    }

    /**
     * Check if user has a permission.
     */
    private function userHasPermission(Model $user, string $permission): bool
    {
        // Contract-based check
        if ($user instanceof MakerCheckerUserContract) {
            return $user->hasMakerCheckerPermission($permission);
        }

        // Method-based check (for backward compatibility)
        if (method_exists($user, 'hasMakerCheckerPermission')) {
            return $user->hasMakerCheckerPermission($permission);
        }

        // Generic hasPermission check
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($permission);
        }

        // Generic can check (Laravel's Gate)
        if (method_exists($user, 'can')) {
            return $user->can($permission);
        }

        return false;
    }

    /**
     * Get the team ID for a user.
     */
    private function getUserTeamId(Model $user): ?int
    {
        if ($user instanceof MakerCheckerUserContract) {
            return $user->getMakerCheckerTeamId();
        }

        if (method_exists($user, 'getMakerCheckerTeamId')) {
            return $user->getMakerCheckerTeamId();
        }

        // Common property names for team/company ID
        foreach (['team_id', 'company_id', 'organization_id', 'tenant_id'] as $property) {
            if (isset($user->{$property})) {
                return (int) $user->{$property};
            }
        }

        return null;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withDefault();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function maker(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function checker(): MorphTo
    {
        return $this->morphTo()->withDefault();
    }

    public function isPending(): bool
    {
        return $this->isOfStatus(RequestStatus::PENDING);
    }

    public function isPartiallyApproved(): bool
    {
        return $this->isOfStatus(RequestStatus::PARTIALLY_APPROVED);
    }

    public function isOfStatus(RequestStatus $status): bool
    {
        return $this->status === $status;
    }

    public function isProcessing(): bool
    {
        return $this->isOfStatus(RequestStatus::PROCESSING);
    }

    public function isApproved(): bool
    {
        return $this->isOfStatus(RequestStatus::APPROVED);
    }

    public function isRejected(): bool
    {
        return $this->isOfStatus(RequestStatus::REJECTED);
    }

    public function isExpired(): bool
    {
        return $this->isOfStatus(RequestStatus::EXPIRED);
    }

    public function isFailed(): bool
    {
        return $this->isOfStatus(RequestStatus::FAILED);
    }

    public function isCancelled(): bool
    {
        return $this->isOfStatus(RequestStatus::CANCELLED);
    }

    /**
     * Check if the request can still be acted upon.
     */
    public function isActionable(): bool
    {
        if ($this->isPending()) {
            return true;
        }

        return $this->isPartiallyApproved();
    }

    /**
     * Check if the request has reached a final state.
     */
    public function isFinalized(): bool
    {
        return in_array($this->status, RequestStatus::getFinalizedStatuses(), true);
    }

    public function isOfType(RequestType $type): bool
    {
        return $this->type === $type;
    }

    /**
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopeStatus(EloquentBuilder $query, RequestStatus $status): EloquentBuilder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopePending(EloquentBuilder $query): EloquentBuilder
    {
        return $query->where('status', RequestStatus::PENDING);
    }

    /**
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopePartiallyApproved(EloquentBuilder $query): EloquentBuilder
    {
        return $query->where('status', RequestStatus::PARTIALLY_APPROVED);
    }

    /**
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopeActionable(EloquentBuilder $query): EloquentBuilder
    {
        return $query->whereIn('status', [RequestStatus::PENDING, RequestStatus::PARTIALLY_APPROVED]);
    }

    /**
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopeOfType(EloquentBuilder $query, RequestType $type): EloquentBuilder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopeForSubject(EloquentBuilder $query, Model $subject): EloquentBuilder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /**
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopeForTeam(EloquentBuilder $query, int $teamId): EloquentBuilder
    {
        return $query->where('team_id', $teamId);
    }

    /**
     * Approve this request.
     *
     * If no approver is provided, the authenticated user is used.
     *
     * @return $this
     */
    public function approve(?Model $approver = null, ?string $role = null, ?string $remarks = null): static
    {
        MakerChecker::approve($this, $approver, $role, $remarks);

        return $this;
    }

    /**
     * Reject this request.
     *
     * If no rejector is provided, the authenticated user is used.
     *
     * @return $this
     */
    public function reject(?Model $rejector = null, ?string $remarks = null): static
    {
        MakerChecker::reject($this, $rejector, $remarks);

        return $this;
    }

    /**
     * Cancel this request.
     *
     * If no canceller is provided, the authenticated user is used.
     *
     * @return $this
     */
    public function cancel(?Model $canceller = null, ?string $remarks = null): static
    {
        MakerChecker::cancel($this, $canceller, $remarks);

        return $this;
    }
}
