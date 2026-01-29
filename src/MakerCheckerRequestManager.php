<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Moffhub\MakerChecker\Contracts\MakerCheckerUserContract;
use Moffhub\MakerChecker\Enums\Hooks;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestCancelled;
use Moffhub\MakerChecker\Events\RequestFailed;
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Exceptions\InvalidRequestTypePassed;
use Moffhub\MakerChecker\Exceptions\ModelCannotCheckRequests;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeCancelled;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Exceptions\RequestCouldNotBeProcessed;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
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
     * Approve a pending maker-checker request.
     */
    public function approve(
        MakerCheckerRequest $request,
        Model $approver,
        ?string $role = null,
        ?string $remarks = null
    ): MakerCheckerRequest {
        $this->assertRequestCanBeChecked($request, $approver);

        return DB::transaction(function () use ($request, $approver, $role, $remarks): \Moffhub\MakerChecker\Models\MakerCheckerRequest {
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

                    // Dispatch the approval event
                    $this->app['events']->dispatch(new RequestApproved($request));
                } else {
                    // If some approvals are done but not all, mark as partially approved
                    $request->update([
                        'status' => RequestStatus::PARTIALLY_APPROVED,
                        'remarks' => $remarks,
                    ]);
                }

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
     * Reject a pending maker-checker request.
     */
    public function reject(
        MakerCheckerRequest $request,
        Model $rejector,
        ?string $remarks = null,
    ): MakerCheckerRequest {
        $this->assertRequestCanBeChecked($request, $rejector);

        return DB::transaction(function () use ($request, $rejector, $remarks): \Moffhub\MakerChecker\Models\MakerCheckerRequest {
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

                // Dispatch rejection event
                $this->app['events']->dispatch(new RequestRejected($request));

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
     */
    public function cancel(
        MakerCheckerRequest $request,
        Model $canceller,
        ?string $remarks = null,
    ): MakerCheckerRequest {
        $this->assertRequestCanBeCancelled($request, $canceller);

        return DB::transaction(function () use ($request, $canceller, $remarks): \Moffhub\MakerChecker\Models\MakerCheckerRequest {
            $request->update([
                'status' => RequestStatus::CANCELLED,
                'checker_type' => $canceller->getMorphClass(),
                'checker_id' => $canceller->getKey(),
                'checked_at' => Carbon::now(),
                'remarks' => $remarks,
            ]);

            $this->app['events']->dispatch(new RequestCancelled($request));

            return $request;
        });
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
            throw RequestCannotBeChecked::create('Cannot act on a non-pending or partially approved request.');
        }

        $requestExpirationInMinutes = data_get($this->configData, 'request_expiration_in_minutes');

        if ($requestExpirationInMinutes && Carbon::now()
            ->diffInMinutes($request->created_at) > $requestExpirationInMinutes) {
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

        if ($callback instanceof \Closure) {
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
     * @throws Exception
     */
    private function fulfillRequest(MakerCheckerRequest $request): void
    {
        if ($request->isOfType(RequestType::CREATE)) {
            $subjectClass = $request->subject_type;
            if (is_array($request->payload) && class_exists($subjectClass)) {
                /**
                 * @var Model $instance
                 */
                $instance = new $subjectClass;
                $instance::query()->firstOrCreate($request->payload);
                $this->deleteRequestIfConfigured($request);
            } else {
                throw new Exception('Payload must be an array');
            }
        } elseif ($request->isOfType(RequestType::UPDATE)) {
            if (is_array($request->payload)) {
                $request->subject->update($request->payload);
                $this->deleteRequestIfConfigured($request);
            } else {
                throw new Exception('Payload must be an array');
            }
        } elseif ($request->isOfType(RequestType::DELETE)) {
            $request->subject->delete();
            $this->deleteRequestIfConfigured($request);
        } elseif ($request->isOfType(RequestType::EXECUTE)) {
            if (is_string($request->executable) && class_exists($request->executable)) {
                $this->app->make($request->executable)->execute($request);
                $this->deleteRequestIfConfigured($request);
            } else {
                throw new Exception('Executable must be a string');
            }
        } else {
            throw InvalidRequestTypePassed::create($request->type);
        }
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
