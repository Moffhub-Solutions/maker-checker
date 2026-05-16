<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Contracts\CallbackServiceInterface;
use Moffhub\MakerChecker\Contracts\MakerCheckerUserContract;
use Moffhub\MakerChecker\Enums\Hooks;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestCancelled;
use Moffhub\MakerChecker\Events\RequestFailed;
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Events\RequestRolledBack;
use Moffhub\MakerChecker\Exceptions\FulfillmentException;
use Moffhub\MakerChecker\Exceptions\InvalidRequestTypePassed;
use Moffhub\MakerChecker\Exceptions\ModelCannotCheckRequests;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeCancelled;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeRolledBack;
use Moffhub\MakerChecker\Exceptions\RequestCouldNotBeProcessed;
use Moffhub\MakerChecker\Exceptions\UnauthorizedApproverException;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Relations\RelationOperation;
use Moffhub\MakerChecker\Services\AuditService;
use Moffhub\MakerChecker\Services\NotificationService;
use Throwable;

class MakerCheckerRequestManager
{
    private readonly array $configData;

    public function __construct(private readonly Application $app)
    {
        $this->configData = $app['config']['maker-checker'];
    }

    /**
     * Begin initiating a new request.
     */
    public function request(): RequestBuilder
    {
        return $this->app[RequestBuilder::class];
    }

    /**
     * Create a maker-checker request to create a new model.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    public function create(string $modelClass, array $attributes, ?string $description = null): MakerCheckerRequest
    {
        $maker = $this->getAuthenticatedUser();

        $builder = $this->request()
            ->toCreate($modelClass, $attributes)
            ->madeBy($maker);

        if ($description !== null) {
            $builder->description($description);
        }

        return $builder->save();
    }

    /**
     * Create a maker-checker request to update an existing model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Model $model, array $attributes, ?string $description = null): MakerCheckerRequest
    {
        $maker = $this->getAuthenticatedUser();

        $builder = $this->request()
            ->toUpdate($model, $attributes)
            ->madeBy($maker);

        if ($description !== null) {
            $builder->description($description);
        }

        return $builder->save();
    }

    /**
     * Create a maker-checker request to delete a model.
     */
    public function delete(Model $model, ?string $description = null): MakerCheckerRequest
    {
        $maker = $this->getAuthenticatedUser();

        $builder = $this->request()
            ->toDelete($model)
            ->madeBy($maker);

        if ($description !== null) {
            $builder->description($description);
        }

        return $builder->save();
    }

    /**
     * Create a maker-checker request to execute an action.
     *
     * @param  class-string  $executable
     * @param  array<string, mixed>  $payload
     */
    public function execute(string $executable, array $payload = [], ?string $description = null): MakerCheckerRequest
    {
        $maker = $this->getAuthenticatedUser();

        $builder = $this->request()
            ->toExecute($executable, $payload)
            ->madeBy($maker);

        if ($description !== null) {
            $builder->description($description);
        }

        return $builder->save();
    }

    /**
     * Get the authenticated user.
     *
     * @throws \RuntimeException If no authenticated user is found
     */
    protected function getAuthenticatedUser(): Model
    {
        $user = $this->app['auth']->user();

        if (!$user instanceof Model) {
            throw new \RuntimeException('No authenticated user found. Please log in or pass a user explicitly.');
        }

        return $user;
    }

    /**
     * Get the configuration resolver for model/action-specific settings.
     */
    public function config(): ConfigResolver
    {
        return new ConfigResolver($this->configData);
    }

    /**
     * Define a callback to be executed after any request is initiated.
     */
    public function afterInitiating(Closure $callback): void
    {
        $this->app['events']->listen(RequestInitiated::class, $callback);
    }

    /**
     * Define a callback to be executed after any request is fulfilled.
     */
    public function afterApproving(Closure $callback): void
    {
        $this->app['events']->listen(RequestApproved::class, $callback);
    }

    /**
     * Define a callback to be executed after any request is rejected.
     */
    public function afterRejecting(Closure $callback): void
    {
        $this->app['events']->listen(RequestRejected::class, $callback);
    }

    /**
     * Define a callback to be executed after any request is cancelled.
     */
    public function afterCancelling(Closure $callback): void
    {
        $this->app['events']->listen(RequestCancelled::class, $callback);
    }

    /**
     * Define a callback to be executed in the event of a failure while fulfilling the request.
     */
    public function onFailure(Closure $callback): void
    {
        $this->app['events']->listen(RequestFailed::class, $callback);
    }

    /**
     * Get the notification service for manual notification control.
     */
    public function notifications(): NotificationService
    {
        return $this->app->make(NotificationService::class);
    }

    /**
     * Get the callback service for programmatic callback registration.
     */
    public function callbacks(): CallbackServiceInterface
    {
        return $this->app->make(CallbackServiceInterface::class);
    }

    /**
     * Manually notify approvers about a pending request.
     *
     * Useful when you want to trigger notifications outside the normal flow.
     */
    public function notifyApprovers(MakerCheckerRequest $request, bool $sequential = false): void
    {
        $this->notifications()->notifyPendingApproval($request, $sequential);
    }

    /**
     * Notify the next set of approvers (for sequential approval workflows).
     *
     * Call this after a partial approval to notify the next required role.
     */
    public function notifyNextApprovers(MakerCheckerRequest $request): void
    {
        $this->notifications()->notifyNextApprovers($request);
    }

    /**
     * Approve a pending maker-checker request.
     *
     * If no approver is provided, the authenticated user is used.
     */
    public function approve(
        MakerCheckerRequest $request,
        ?Model $approver = null,
        ?string $role = null,
        ?string $remarks = null
    ): MakerCheckerRequest {
        $approver = $approver ?? $this->getAuthenticatedUser();
        $this->assertRequestCanBeChecked($request, $approver);

        return DB::transaction(function () use ($request, $approver, $role, $remarks): MakerCheckerRequest {
            // Re-fetch with pessimistic lock to prevent race conditions
            $request = MakerCheckerRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->getKey());

            // Re-check actionable status inside the lock
            if (!$request->isActionable()) {
                throw RequestCannotBeChecked::create(
                    "Request is in '{$request->status->value}' status. Only pending or partially approved requests can be approved or rejected."
                );
            }

            $previousStatus = $request->status->value;

            try {
                // Add approval, handling the case where no role is provided
                $request->addApproval($approver, $role);

                // Check if the required approvals threshold is met
                if ($request->hasMetApprovalThreshold()) {
                    $request->update([
                        'status' => RequestStatus::APPROVED,
                        'checked_at' => Carbon::now(),
                        'remarks' => $remarks,
                    ]);

                    // Execute pre-approval hook
                    $this->executeCallbackHook($request, Hooks::PRE_APPROVAL);

                    // Fulfill the request
                    $this->fulfillRequest($request);

                    // Execute post-approval hook
                    $this->executeCallbackHook($request, Hooks::POST_APPROVAL);

                    // Audit log
                    $this->auditLog('approved', $request, $approver, $previousStatus, $request->status->value);

                    // Dispatch the approval event
                    $this->app['events']->dispatch(RequestApproved::fromRequest($request, $approver));
                } else {
                    // If some approvals are done but not all, mark as partially approved
                    $request->update([
                        'status' => RequestStatus::PARTIALLY_APPROVED,
                        'remarks' => $remarks,
                    ]);

                    // Audit log
                    $this->auditLog('partially_approved', $request, $approver, $previousStatus, $request->status->value);
                }

                return $request;
            } catch (Throwable $e) {
                $request->update([
                    'status' => RequestStatus::FAILED,
                    'exception' => (string) $e,
                ]);

                // Audit log
                $this->auditLog('failed', $request, $approver, $previousStatus, RequestStatus::FAILED->value, [
                    'exception' => $e->getMessage(),
                ]);

                // Execute failure hook
                $this->executeCallbackHook($request, Hooks::ON_FAILURE);

                $this->app['events']->dispatch(new RequestFailed($request, $e));

                throw RequestCouldNotBeProcessed::create($e->getMessage(), $e);
            }
        });
    }

    /**
     * Reject a pending maker-checker request.
     *
     * If no rejector is provided, the authenticated user is used.
     */
    public function reject(
        MakerCheckerRequest $request,
        ?Model $rejector = null,
        ?string $remarks = null,
    ): MakerCheckerRequest {
        $rejector = $rejector ?? $this->getAuthenticatedUser();
        $this->assertRequestCanBeChecked($request, $rejector);

        return DB::transaction(function () use ($request, $rejector, $remarks): MakerCheckerRequest {
            $previousStatus = $request->status->value;

            try {
                // Execute pre-rejection hook
                $this->executeCallbackHook($request, Hooks::PRE_REJECTION);

                $request->update([
                    'status' => RequestStatus::REJECTED,
                    'checker_type' => $rejector->getMorphClass(),
                    'checker_id' => $rejector->getKey(),
                    'checked_at' => Carbon::now(),
                    'remarks' => $remarks,
                ]);

                // Execute post-rejection hook
                $this->executeCallbackHook($request, Hooks::POST_REJECTION);

                // Audit log
                $this->auditLog('rejected', $request, $rejector, $previousStatus, $request->status->value);

                // Dispatch rejection event
                $this->app['events']->dispatch(RequestRejected::fromRequest($request, $rejector, $remarks));

                return $request;
            } catch (Throwable $e) {
                $request->update([
                    'status' => RequestStatus::FAILED,
                    'exception' => (string) $e,
                ]);

                // Execute failure hook
                $this->executeCallbackHook($request, Hooks::ON_FAILURE);

                $this->app['events']->dispatch(new RequestFailed($request, $e));

                throw RequestCouldNotBeProcessed::create($e->getMessage(), $e);
            }
        });
    }

    /**
     * Cancel a pending maker-checker request.
     *
     * Only the maker of the request can cancel it.
     * If no canceller is provided, the authenticated user is used.
     */
    public function cancel(
        MakerCheckerRequest $request,
        ?Model $canceller = null,
        ?string $remarks = null,
    ): MakerCheckerRequest {
        $canceller = $canceller ?? $this->getAuthenticatedUser();
        $this->assertRequestCanBeCancelled($request, $canceller);

        return DB::transaction(function () use ($request, $canceller, $remarks): MakerCheckerRequest {
            $previousStatus = $request->status->value;

            $request->update([
                'status' => RequestStatus::CANCELLED,
                'checker_type' => $canceller->getMorphClass(),
                'checker_id' => $canceller->getKey(),
                'checked_at' => Carbon::now(),
                'remarks' => $remarks,
            ]);

            // Audit log
            $this->auditLog('cancelled', $request, $canceller, $previousStatus, $request->status->value);

            $this->app['events']->dispatch(RequestCancelled::fromRequest($request, $remarks));

            return $request;
        });
    }

    /**
     * Rollback an already-approved maker-checker request.
     *
     * For CREATE requests, the created model is deleted.
     * For UPDATE requests, the model is reverted to its original values.
     * DELETE requests cannot be rolled back.
     *
     * Only users with admin role or whitelisted emails can perform rollbacks.
     */
    public function rollback(
        MakerCheckerRequest $request,
        ?Model $actor = null,
        ?string $remarks = null,
    ): MakerCheckerRequest {
        $actor = $actor ?? $this->getAuthenticatedUser();
        $this->assertRequestCanBeRolledBack($request, $actor);

        return DB::transaction(function () use ($request, $actor, $remarks): MakerCheckerRequest {
            $previousStatus = $request->status->value;

            try {
                $this->reverseFulfillment($request);

                $request->update([
                    'status' => RequestStatus::ROLLED_BACK,
                    'checker_type' => $actor->getMorphClass(),
                    'checker_id' => $actor->getKey(),
                    'checked_at' => Carbon::now(),
                    'remarks' => $remarks,
                ]);

                // Audit log
                $this->auditLog('rolled_back', $request, $actor, $previousStatus, $request->status->value);

                // Dispatch rollback event
                $this->app['events']->dispatch(RequestRolledBack::fromRequest($request, $remarks));

                return $request;
            } catch (RequestCannotBeRolledBack $e) {
                throw $e;
            } catch (Throwable $e) {
                $request->update([
                    'status' => RequestStatus::FAILED,
                    'exception' => (string) $e,
                ]);

                // Audit log
                $this->auditLog('rollback_failed', $request, $actor, $previousStatus, RequestStatus::FAILED->value, [
                    'exception' => $e->getMessage(),
                ]);

                $this->app['events']->dispatch(new RequestFailed($request, $e));

                throw RequestCouldNotBeProcessed::create($e->getMessage(), $e);
            }
        });
    }

    /**
     * Define a callback to be executed after any request is rolled back.
     */
    public function afterRollingBack(Closure $callback): void
    {
        $this->app['events']->listen(RequestRolledBack::class, $callback);
    }

    private function assertRequestCanBeRolledBack(MakerCheckerRequest $request, Model $actor): void
    {
        $requestModelClass = MakerCheckerServiceProvider::getRequestModelClass();

        if (!$request instanceof $requestModelClass) {
            throw RequestCannotBeRolledBack::create("The request model passed must be an instance of $requestModelClass");
        }

        if (!$request->isOfStatus(RequestStatus::APPROVED)) {
            throw RequestCannotBeRolledBack::create('Only approved requests can be rolled back.');
        }

        if ($request->isOfType(RequestType::DELETE)) {
            throw RequestCannotBeRolledBack::create('Delete requests cannot be rolled back.');
        }

        // Only whitelisted emails or admin users can rollback
        $whitelistEmails = $this->getWhitelistEmails();
        $actorEmail = $this->getUserEmail($actor);
        $isWhitelisted = $actorEmail && in_array($actorEmail, $whitelistEmails, true);

        if (!$isWhitelisted && !$this->userIsAdmin($actor)) {
            throw RequestCannotBeRolledBack::create('You are not authorized to rollback this request.');
        }
    }

    /**
     * Check if a user has admin privileges for rollback authorization.
     */
    private function userIsAdmin(Model $user): bool
    {
        if ($user instanceof MakerCheckerUserContract) {
            return $user->hasMakerCheckerPermission('maker-checker.rollback');
        }

        if (method_exists($user, 'hasMakerCheckerPermission')) {
            return $user->hasMakerCheckerPermission('maker-checker.rollback');
        }

        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission('maker-checker.rollback');
        }

        if (method_exists($user, 'can')) {
            return $user->can('maker-checker.rollback');
        }

        return false;
    }

    /**
     * Reverse the fulfillment of a request.
     *
     * @throws RequestCannotBeRolledBack
     */
    private function reverseFulfillment(MakerCheckerRequest $request): void
    {
        if ($request->isOfType(RequestType::CREATE)) {
            $subjectClass = $request->subject_type;
            if (!class_exists($subjectClass)) {
                throw RequestCannotBeRolledBack::create("Subject class '{$subjectClass}' does not exist.");
            }

            // Find and delete the created model
            /** @var Model $instance */
            $instance = new $subjectClass;
            $model = $instance::query()->where($request->payload)->first();

            if ($model === null) {
                throw RequestCannotBeRolledBack::create('The created model could not be found for rollback.');
            }

            $model->delete();
        } elseif ($request->isOfType(RequestType::UPDATE)) {
            $originalValues = data_get($request->metadata, 'original_values');

            if (empty($originalValues) || !is_array($originalValues)) {
                throw RequestCannotBeRolledBack::create(
                    'Original values were not captured when this request was created. Rollback is not possible.'
                );
            }

            $subject = $request->subject;

            if (!$subject->exists) {
                throw RequestCannotBeRolledBack::create('The subject model no longer exists.');
            }

            $subject->update($originalValues);
        } elseif ($request->isOfType(RequestType::RELATION)) {
            $original = data_get($request->metadata, 'original_values');

            if (empty($original) || !is_array($original)) {
                throw RequestCannotBeRolledBack::create(
                    'Original relationship state was not captured when this request was created. Rollback is not possible.'
                );
            }

            $parent = $request->subject;

            if (!$parent->exists) {
                throw RequestCannotBeRolledBack::create('The parent model no longer exists.');
            }

            $payload = $request->payload;

            $this->withoutModelInterception(
                $parent,
                fn() => RelationOperation::reverse($parent, $payload, $original)
            );
        } else {
            throw RequestCannotBeRolledBack::create("Rollback is not supported for '{$request->type->value}' requests.");
        }
    }

    private function assertRequestCanBeCancelled(MakerCheckerRequest $request, Model $canceller): void
    {
        $requestModelClass = MakerCheckerServiceProvider::getRequestModelClass();

        if (!$request instanceof $requestModelClass) {
            throw RequestCannotBeCancelled::create("The request model passed must be an instance of $requestModelClass");
        }

        // Only pending or partially approved requests can be cancelled
        if (!$request->isActionable()) {
            throw RequestCannotBeCancelled::create('Only pending or partially approved requests can be cancelled.');
        }

        // Check if canceller is the maker
        if (!$canceller->is($request->maker)) {
            // Allow whitelisted emails to cancel any request
            $whitelistEmails = $this->getWhitelistEmails();
            $cancellerEmail = $this->getUserEmail($canceller);

            if (!$cancellerEmail || !in_array($cancellerEmail, $whitelistEmails, true)) {
                throw RequestCannotBeCancelled::create('Only the maker of the request can cancel it.');
            }
        }
    }

    private function assertRequestCanBeChecked(MakerCheckerRequest $request, Model $checker): void
    {
        $whitelistEmails = $this->getWhitelistEmails();
        $requestModelClass = MakerCheckerServiceProvider::getRequestModelClass();

        if (!$request instanceof $requestModelClass) {
            throw RequestCannotBeChecked::create("The request model passed must be an instance of $requestModelClass");
        }

        $this->assertModelCanCheckRequests($checker);

        if (!$request->isActionable()) {
            throw RequestCannotBeChecked::create(
                "Request is in '{$request->status->value}' status. Only pending or partially approved requests can be approved or rejected."
            );
        }

        $requestExpirationInMinutes = data_get($this->configData, 'request_expiration_in_minutes');

        if ($requestExpirationInMinutes && abs(Carbon::now()
            ->diffInMinutes($request->created_at)) > $requestExpirationInMinutes) {
            throw RequestCannotBeChecked::create('Expired request.');
        }

        if ($checker->is($request->maker)) {
            $checkerEmail = $this->getUserEmail($checker);

            if (!$checkerEmail) {
                throw RequestCannotBeChecked::create('Checkers must have emails attached to their accounts.');
            }
            if (!in_array($checkerEmail, $whitelistEmails, true)) {
                throw RequestCannotBeChecked::create('Request checker cannot be the same as the maker.');
            }
        }

        // Validate user-specific approval requirements if applicable
        $this->assertUserCanApproveIfRequired($request, $checker);

        // Validate checker is a legitimate approver for this request
        $this->assertCheckerIsAuthorizedApprover($request, $checker);
    }

    /**
     * Assert that the checker is an allowed user if the request requires specific user approvals.
     */
    private function assertUserCanApproveIfRequired(MakerCheckerRequest $request, Model $checker): void
    {
        $requiredApprovals = $request->required_approvals ?? [];

        // Check if there are user-specific requirements
        if (!isset($requiredApprovals['users']) || empty($requiredApprovals['users'])) {
            return;
        }

        $pendingUsers = $request->getPendingUsers();

        if (empty($pendingUsers)) {
            // All required users have already approved
            return;
        }

        // Get checker's email
        $checkerEmail = $this->getUserEmail($checker);
        $checkerId = (string) $checker->getKey();

        // Check if the checker is one of the pending users
        $isRequiredUser = in_array($checkerEmail, $pendingUsers, true)
            || in_array($checkerId, $pendingUsers, true);

        // Also check if there are pending roles that this user might fulfill
        $hasPendingRoles = !empty($request->getPendingRoles());

        // If there are no pending roles and the user is not a required user, they cannot approve
        if (!$hasPendingRoles && !$isRequiredUser) {
            throw RequestCannotBeChecked::create(
                'This request requires approval from specific users. You are not authorized to approve it.'
            );
        }
    }

    /**
     * Get whitelist emails from config.
     *
     * @return array<string>
     */
    private function getWhitelistEmails(): array
    {
        $whitelistEmails = data_get($this->configData, 'whitelisted_emails');

        if (!empty($whitelistEmails) && is_string($whitelistEmails)) {
            return collect(explode(',', $whitelistEmails))
                ->map(fn(string $email): string => trim($email))
                ->filter()
                ->values()
                ->toArray();
        }

        return [];
    }

    /**
     * Get user email from model.
     */
    private function getUserEmail(Model $user): ?string
    {
        if ($user instanceof MakerCheckerUserContract) {
            return $user->getMakerCheckerEmail();
        }

        if (method_exists($user, 'getMakerCheckerEmail')) {
            return $user->getMakerCheckerEmail();
        }

        // Check for email attribute
        if (isset($user->email) && is_string($user->email)) {
            return $user->email;
        }

        return null;
    }

    /**
     * Assert that the checker is a recognized approver for this request.
     *
     * Verifies the checker exists in the resolved approvers list (by role or user identity).
     * Whitelisted emails bypass this check.
     * If the resolver returns an empty collection (e.g. no user model configured),
     * the check is skipped to maintain backwards compatibility.
     */
    private function assertCheckerIsAuthorizedApprover(MakerCheckerRequest $request, Model $checker): void
    {
        $requiredApprovals = $request->required_approvals ?? [];

        // If no specific approvals are required, any valid checker can approve
        if (empty($requiredApprovals)) {
            return;
        }

        // Whitelisted emails bypass approver validation
        $whitelistEmails = $this->getWhitelistEmails();
        $checkerEmail = $this->getUserEmail($checker);
        if ($checkerEmail && in_array($checkerEmail, $whitelistEmails, true)) {
            return;
        }

        $resolver = $this->app->make(ApproverResolver::class);
        $allApprovers = $resolver->getAllApprovers($request);

        // If the resolver returned no approvers (e.g. no user model configured),
        // skip this check — the other assertion methods already validate
        // user-specific and role-specific requirements.
        if ($allApprovers->isEmpty()) {
            return;
        }

        $isAuthorized = $allApprovers->contains(
            fn(Model $approver): bool => $approver->getKey() === $checker->getKey()
                && $approver->getMorphClass() === $checker->getMorphClass()
        );

        if (!$isAuthorized) {
            throw UnauthorizedApproverException::create(
                'You are not an authorized approver for this request.'
            );
        }
    }

    private function assertModelCanCheckRequests(Model $checker): void
    {
        $checkerModel = $checker::class;
        $allowedCheckers = data_get($this->configData, 'whitelisted_models.checker');

        if (is_string($allowedCheckers)) {
            $allowedCheckers = [$allowedCheckers];
        }

        if (!is_array($allowedCheckers)) {
            $allowedCheckers = [];
        }

        if ($allowedCheckers !== [] && !in_array($checkerModel, $allowedCheckers)) {
            throw ModelCannotCheckRequests::create($checkerModel);
        }
    }

    private function executeCallbackHook(MakerCheckerRequest $request, Hooks $hook): void
    {
        $callback = $this->getHook($request, $hook);

        if ($callback instanceof Closure) {
            $callback($request);
        }
    }

    private function getHook(MakerCheckerRequest $request, Hooks $hookName): ?Closure
    {
        $hooks = data_get($request->metadata, 'hooks', []);

        $serializedClosure = data_get($hooks, $hookName->value);

        return $serializedClosure ? unserialize($serializedClosure)->getClosure() : null;
    }

    /**
     * Log an audit entry for a maker-checker action.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function auditLog(
        string $action,
        MakerCheckerRequest $request,
        Model $actor,
        string $previousStatus,
        string $newStatus,
        array $metadata = [],
    ): void {
        try {
            $this->app->make(AuditService::class)->log(
                $action,
                $request,
                $actor->getMorphClass(),
                $actor->getKey(),
                $previousStatus,
                $newStatus,
                $metadata,
            );
        } catch (Throwable) {
            // Audit logging should never break the main flow
        }
    }

    private function fulfillRequest(MakerCheckerRequest $request): void
    {
        if ($request->isOfType(RequestType::CREATE)) {
            $subjectClass = $request->subject_type;
            if (!is_array($request->payload)) {
                throw FulfillmentException::invalidPayload('an array');
            }
            if (!class_exists($subjectClass)) {
                throw FulfillmentException::invalidExecutable("Subject class '{$subjectClass}' does not exist.");
            }
            /** @var Model $instance */
            $instance = new $subjectClass;
            $payload = $request->payload;
            // Bypass interception so a model using RequiresApproval is not
            // re-routed back into the approval flow while being fulfilled.
            $this->withoutModelInterception(
                $subjectClass,
                fn() => $instance::query()->firstOrCreate($payload)
            );
            $this->deleteRequestIfConfigured($request);
        } elseif ($request->isOfType(RequestType::UPDATE)) {
            if (!is_array($request->payload)) {
                throw FulfillmentException::invalidPayload('an array');
            }
            $subject = $request->subject;
            $payload = $request->payload;
            $this->withoutModelInterception(
                $subject,
                fn() => $subject->update($payload)
            );
            $this->deleteRequestIfConfigured($request);
        } elseif ($request->isOfType(RequestType::DELETE)) {
            $subject = $request->subject;
            $this->withoutModelInterception(
                $subject,
                fn() => $subject->delete()
            );
            $this->deleteRequestIfConfigured($request);
        } elseif ($request->isOfType(RequestType::EXECUTE)) {
            if (!is_string($request->executable) || !class_exists($request->executable)) {
                throw FulfillmentException::invalidExecutable(
                    'Executable must be a valid class name. Got: '.(is_string($request->executable) ? $request->executable : gettype($request->executable))
                );
            }
            $this->app->make($request->executable)->execute($request);
            $this->deleteRequestIfConfigured($request);
        } elseif ($request->isOfType(RequestType::RELATION)) {
            if (!is_array($request->payload)) {
                throw FulfillmentException::invalidPayload('an array');
            }

            $parent = $request->subject;

            if (!$parent->exists) {
                throw FulfillmentException::relationError('The parent model no longer exists.');
            }

            $payload = $request->payload;

            $this->withoutModelInterception(
                $parent,
                fn() => RelationOperation::apply($parent, $payload)
            );

            $this->deleteRequestIfConfigured($request);
        } else {
            throw InvalidRequestTypePassed::create($request->type);
        }
    }

    /**
     * Run a fulfillment/rollback operation without re-triggering maker-checker
     * interception on models that use RequiresApproval/InterceptsRelationships.
     *
     * @param  Model|class-string  $target
     */
    private function withoutModelInterception(Model|string $target, Closure $callback): mixed
    {
        $class = is_string($target) ? $target : $target::class;

        if (method_exists($class, 'withoutApprovalDo')) {
            return $class::withoutApprovalDo($callback);
        }

        return $callback();
    }

    /**
     * Delete the request based on configuration.
     */
    private function deleteRequestIfConfigured(MakerCheckerRequest $request): void
    {
        $deleteOnCompletion = data_get($this->configData, 'delete_on_completion', true);

        if (!$deleteOnCompletion) {
            return;
        }

        $softDeleteOnCompletion = data_get($this->configData, 'soft_delete_on_completion', false);

        if ($softDeleteOnCompletion && method_exists($request, 'trashed')) {
            $request->delete();
        } else {
            $request->forceDelete();
        }
    }
}
