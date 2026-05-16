<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\ConfigResolver;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Exceptions\PendingApprovalException;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Relations\PendingRelationship;

/**
 * Trait to automatically intercept model create/update/delete operations
 * and route them through maker-checker approval workflow.
 *
 * Add this trait to any Eloquent model that requires approval for changes.
 *
 * @mixin Model
 *
 * @example
 * ```php
 * class Post extends Model
 * {
 *     use RequiresApproval;
 *
 *     // Optionally configure which actions require approval
 *     protected static array $requiresApprovalFor = ['create', 'delete'];
 *
 *     // Optionally define approval requirements
 *     protected static array $approvalRequirements = [
 *         'create' => ['editor' => 1],
 *         'delete' => ['admin' => 2],
 *     ];
 * }
 *
 * // Now when you create a post, it returns false and creates an approval request:
 * $post = new Post(['title' => 'My Post']);
 * $saved = $post->save(); // Returns false
 *
 * if (!$saved && Post::wasIntercepted()) {
 *     $request = Post::getInterceptedRequest();
 *     return response()->json([
 *         'message' => 'Pending approval',
 *         'request_id' => $request->id,
 *     ], 202);
 * }
 *
 * // Or use the withoutApproval scope to bypass:
 * $post = Post::createWithoutApproval(['title' => 'My Post']); // Creates directly
 * ```
 */
trait RequiresApproval
{
    /**
     * Flag to bypass approval for the current operation.
     */
    protected static bool $bypassApproval = false;

    /**
     * The authenticated user making the request.
     * Set this before performing operations that require approval.
     */
    protected static ?Model $approvalMaker = null;

    /**
     * The last intercepted maker-checker request.
     */
    protected static ?MakerCheckerRequest $interceptedRequest = null;

    /**
     * Whether to throw an exception when intercepted (default: false).
     * Set to true for backwards compatibility or if you prefer exception handling.
     */
    protected static bool $throwOnIntercept = false;

    /**
     * Boot the trait.
     */
    public static function bootRequiresApproval(): void
    {
        // Intercept creating event - return false to halt
        static::creating(function (Model $model) {
            if (static::shouldInterceptAction(RequestType::CREATE)) {
                return static::interceptCreate($model);
            }

            return true;
        });

        // Intercept updating event - return false to halt
        static::updating(function (Model $model) {
            if (static::shouldInterceptAction(RequestType::UPDATE)) {
                return static::interceptUpdate($model);
            }

            return true;
        });

        // Intercept deleting event - return false to halt
        static::deleting(function (Model $model) {
            if (static::shouldInterceptAction(RequestType::DELETE)) {
                return static::interceptDelete($model);
            }

            return true;
        });
    }

    /**
     * Enable throwing exceptions when intercepted.
     */
    public static function throwOnIntercept(bool $throw = true): void
    {
        static::$throwOnIntercept = $throw;
    }

    /**
     * Set the user making the request for approval tracking.
     */
    public static function setApprovalMaker(?Model $user): void
    {
        static::$approvalMaker = $user;
    }

    /**
     * Get the user making the request.
     * Defaults to the authenticated user.
     */
    public static function getApprovalMaker(): ?Model
    {
        if (static::$approvalMaker !== null) {
            return static::$approvalMaker;
        }

        // Try to get from auth
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }

    /**
     * Check if the last operation was intercepted for approval.
     */
    public static function wasIntercepted(): bool
    {
        return static::$interceptedRequest !== null;
    }

    /**
     * Get the last intercepted maker-checker request.
     */
    public static function getInterceptedRequest(): ?MakerCheckerRequest
    {
        return static::$interceptedRequest;
    }

    /**
     * Clear the intercepted request state.
     */
    public static function clearInterceptedRequest(): void
    {
        static::$interceptedRequest = null;
    }

    /**
     * Execute operations without approval requirements.
     *
     * @return class-string<static>
     */
    public static function withoutApproval(): string
    {
        static::$bypassApproval = true;

        return static::class;
    }

    /**
     * Reset the bypass flag after operation.
     */
    public static function resetApprovalBypass(): void
    {
        static::$bypassApproval = false;
    }

    /**
     * Begin an approval-gated relationship change.
     *
     * Native relationship writes (attach/detach/sync/associate/...) bypass
     * Eloquent model events, so they are not caught by the create/update/
     * delete interception. Route them explicitly through this method:
     *
     * ```php
     * $employee->requestRelation('compensations')->attach($compensation);
     * ```
     */
    public function requestRelation(string $relation): PendingRelationship
    {
        return new PendingRelationship($this, $relation);
    }

    /**
     * Public bypass check for relationship proxies. Consumes the one-time
     * bypass flag exactly like the create/update/delete interception does.
     */
    public static function approvalBypassActive(): bool
    {
        return static::shouldBypassApproval();
    }

    /**
     * Record an intercepted relationship request, mirroring the behaviour of
     * intercepted create/update/delete operations (throw or return false).
     *
     * @throws PendingApprovalException
     */
    public static function handleRelationIntercept(MakerCheckerRequest $request, string $message): bool
    {
        return static::handleIntercept($request, $message);
    }

    /**
     * Check if approval should be bypassed for current operation.
     */
    protected static function shouldBypassApproval(): bool
    {
        if (static::$bypassApproval) {
            static::resetApprovalBypass();

            return true;
        }

        return false;
    }

    /**
     * Determine if the given action should be intercepted.
     */
    protected static function shouldInterceptAction(RequestType $action): bool
    {
        if (static::shouldBypassApproval()) {
            return false;
        }

        // Check if model specifies which actions require approval
        if (property_exists(static::class, 'requiresApprovalFor')) {
            /** @var array<string> $actions */
            $actions = static::$requiresApprovalFor;

            return in_array($action->value, $actions, true);
        }

        // Check if model implements MakerCheckerConfigurable
        if (method_exists(static::class, 'requiresMakerChecker')) {
            return static::requiresMakerChecker($action);
        }

        // Use ConfigResolver to check if maker-checker is required (respects database settings)
        return static::getConfigResolver()->isRequired(static::class, $action);
    }

    /**
     * Get approval requirements for an action.
     *
     * @return array<string, int>
     */
    protected static function getApprovalRequirements(RequestType $action): array
    {
        // Check model property first (highest priority)
        if (property_exists(static::class, 'approvalRequirements')) {
            /** @var array<string, array<string, int>> $requirements */
            $requirements = static::$approvalRequirements;

            if (isset($requirements[$action->value])) {
                return $requirements[$action->value];
            }
        }

        // Use ConfigResolver which handles:
        // - MakerCheckerConfigurable interface
        // - Database config (if driver is 'database')
        // - File config (model-specific and global settings)
        return static::getConfigResolver()->getApprovals(static::class, $action);
    }

    /**
     * Get the ConfigResolver instance.
     */
    protected static function getConfigResolver(): ConfigResolver
    {
        return app(ConfigResolver::class);
    }

    /**
     * Get description for the approval request.
     */
    protected static function getApprovalDescription(RequestType $action, array $payload): string
    {
        // Use ConfigResolver which handles MakerCheckerConfigurable and provides default descriptions
        return static::getConfigResolver()->getDescription(static::class, $action, $payload);
    }

    /**
     * Handle the intercept result - throw exception or return false.
     *
     * @throws PendingApprovalException
     */
    protected static function handleIntercept(MakerCheckerRequest $request, string $message): bool
    {
        static::$interceptedRequest = $request;

        if (static::$throwOnIntercept) {
            throw new PendingApprovalException($request, $message);
        }

        // Return false to halt the model operation
        return false;
    }

    /**
     * Intercept create operation and route to approval.
     *
     * @return bool Returns false to halt the operation, true to continue
     *
     * @throws PendingApprovalException If throwOnIntercept is enabled
     */
    protected static function interceptCreate(Model $model): bool
    {
        // Clear any previous intercepted request
        static::$interceptedRequest = null;

        $maker = static::getApprovalMaker();

        if ($maker === null) {
            // If no maker available, allow the operation to proceed
            // This handles cases like seeders, migrations, etc.
            return true;
        }

        $payload = $model->getAttributes();
        $approvals = static::getApprovalRequirements(RequestType::CREATE);
        $description = static::getApprovalDescription(RequestType::CREATE, $payload);

        $builder = MakerChecker::request()
            ->toCreate(static::class, $payload)
            ->madeBy($maker)
            ->description($description);

        if (!empty($approvals)) {
            $builder->withApprovals($approvals);
        }

        $request = $builder->save();

        return static::handleIntercept($request, 'Create operation requires approval.');
    }

    /**
     * Intercept update operation and route to approval.
     *
     * @return bool Returns false to halt the operation, true to continue
     *
     * @throws PendingApprovalException If throwOnIntercept is enabled
     */
    protected static function interceptUpdate(Model $model): bool
    {
        static::$interceptedRequest = null;

        $maker = static::getApprovalMaker();

        if ($maker === null) {
            return true;
        }

        $payload = $model->getDirty();

        if (empty($payload)) {
            return true; // No changes to approve
        }

        $approvals = static::getApprovalRequirements(RequestType::UPDATE);
        $description = static::getApprovalDescription(RequestType::UPDATE, $payload);

        $builder = MakerChecker::request()
            ->toUpdate($model, $payload)
            ->madeBy($maker)
            ->description($description);

        if (!empty($approvals)) {
            $builder->withApprovals($approvals);
        }

        $request = $builder->save();

        return static::handleIntercept($request, 'Update operation requires approval.');
    }

    /**
     * Intercept delete operation and route to approval.
     *
     * @return bool Returns false to halt the operation, true to continue
     *
     * @throws PendingApprovalException If throwOnIntercept is enabled
     */
    protected static function interceptDelete(Model $model): bool
    {
        static::$interceptedRequest = null;

        $maker = static::getApprovalMaker();

        if ($maker === null) {
            return true;
        }

        $approvals = static::getApprovalRequirements(RequestType::DELETE);
        $description = static::getApprovalDescription(RequestType::DELETE, $model->toArray());

        $builder = MakerChecker::request()
            ->toDelete($model)
            ->madeBy($maker)
            ->description($description);

        if (!empty($approvals)) {
            $builder->withApprovals($approvals);
        }

        $request = $builder->save();

        return static::handleIntercept($request, 'Delete operation requires approval.');
    }

    /**
     * Create a model directly, bypassing approval.
     * Use this for internal/system operations.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithoutApproval(array $attributes = []): static
    {
        static::$bypassApproval = true;

        try {
            /** @var static $model */
            $model = static::create($attributes);

            return $model;
        } finally {
            static::resetApprovalBypass();
        }
    }

    /**
     * Update the model directly, bypassing approval.
     * Use this for internal/system operations.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateWithoutApproval(array $attributes = []): bool
    {
        static::$bypassApproval = true;

        try {
            return $this->update($attributes);
        } finally {
            static::resetApprovalBypass();
        }
    }

    /**
     * Delete the model directly, bypassing approval.
     * Use this for internal/system operations.
     */
    public function deleteWithoutApproval(): ?bool
    {
        static::$bypassApproval = true;

        try {
            return $this->delete();
        } finally {
            static::resetApprovalBypass();
        }
    }

    /**
     * Execute a callback without approval requirements.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutApprovalDo(callable $callback): mixed
    {
        static::$bypassApproval = true;

        try {
            return $callback();
        } finally {
            static::resetApprovalBypass();
        }
    }

    /**
     * Check if there's a pending approval request for this model and action.
     */
    public function hasPendingApproval(?RequestType $action = null): bool
    {
        $query = MakerCheckerRequest::query()
            ->where('subject_type', static::class)
            ->where('subject_id', $this->getKey())
            ->actionable();

        if ($action !== null) {
            $query->where('type', $action);
        }

        return $query->exists();
    }

    /**
     * Get pending approval requests for this model.
     *
     * @return Collection<int, MakerCheckerRequest>
     */
    public function getPendingApprovals(?RequestType $action = null): Collection
    {
        $query = MakerCheckerRequest::query()
            ->where('subject_type', static::class)
            ->where('subject_id', $this->getKey())
            ->actionable();

        if ($action !== null) {
            $query->where('type', $action);
        }

        return $query->get();
    }
}
