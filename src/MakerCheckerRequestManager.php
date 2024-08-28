<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Moffhub\MakerChecker\Enums\Hooks;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestFailed;
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Exceptions\InvalidRequestTypePassed;
use Moffhub\MakerChecker\Exceptions\ModelCannotCheckRequests;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Exceptions\RequestCouldNotBeProcessed;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Throwable;

class MakerCheckerRequestManager
{
    private array $configData;

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
        } finally {
            // Execute post-approval hook
            if ($request->status === RequestStatus::APPROVED) {
                $this->executeCallbackHook($request, Hooks::POST_APPROVAL);
            }
        }
    }

    private function assertRequestCanBeChecked(MakerCheckerRequest $request, Model $checker): void
    {
        $whitelistEmails = data_get($this->configData, 'whitelisted_emails');
        if (!empty($whitelistEmails)) {
            $whitelistEmails = $whitelistEmails->explode(',')
                ->map(fn(string $email) => trim($email))
                ->filter();
        } else {
            $whitelistEmails = [];
        }

        $requestModelClass = MakerCheckerServiceProvider::getRequestModelClass();

        if (!is_a($request, $requestModelClass)) {
            throw RequestCannotBeChecked::create("The request model passed must be an instance of $requestModelClass");
        }

        $this->assertModelCanCheckRequests($checker);
        $isPendingOrPartiallyApproved = $request->isOfStatus(RequestStatus::PENDING) || $request->isOfStatus(RequestStatus::PARTIALLY_APPROVED);
        if (!$isPendingOrPartiallyApproved) {
            throw RequestCannotBeChecked::create('Cannot act on a non-pending or partially approved request.');
        }

        $requestExpirationInMinutes = data_get($this->configData, 'request_expiration_in_minutes');

        if ($requestExpirationInMinutes && Carbon::now()
            ->diffInMinutes($request->created_at) > $requestExpirationInMinutes) {
            throw RequestCannotBeChecked::create('Expired request.');
        }

        if ($checker->is($request->maker)) {
            if (!$checker->hasAttribute('email') || empty($checker->email)) {
                throw RequestCannotBeChecked::create('Checkers must have emails attached to their accounts.');
            }
            if (!in_array($checker->email, $whitelistEmails)) {
                throw RequestCannotBeChecked::create('Request checker cannot be the same as the maker.');
            }
        }
    }

    private function assertModelCanCheckRequests(Model $checker): void
    {
        $checkerModel = get_class($checker);
        $allowedCheckers = data_get($this->configData, 'whitelisted_models.checker');

        if (is_string($allowedCheckers)) {
            $allowedCheckers = [$allowedCheckers];
        }

        if (!is_array($allowedCheckers)) {
            $allowedCheckers = [];
        }

        if (!empty($allowedCheckers) && !in_array($checkerModel, $allowedCheckers)) {
            throw ModelCannotCheckRequests::create($checkerModel);
        }
    }

    private function executeCallbackHook(MakerCheckerRequest $request, Hooks $hook): void
    {
        $callback = $this->getHook($request, $hook);

        if ($callback) {
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
                $request->delete();
            } else {
                throw new Exception('Payload must be an array');
            }
        } elseif ($request->isOfType(RequestType::UPDATE)) {
            if (is_array($request->payload)) {
                $request->subject->update($request->payload);
                $request->delete();
            } else {
                throw new Exception('Payload must be an array');
            }
        } elseif ($request->isOfType(RequestType::DELETE)) {
            $request->subject->delete();
            $request->delete();
        } elseif ($request->isOfType(RequestType::EXECUTE)) {
            if (is_string($request->executable) && class_exists($request->executable)) {
                $this->app->make($request->executable)->execute($request);
                $request->delete();
            } else {
                throw new Exception('Executable must be a string');
            }
        } else {
            throw InvalidRequestTypePassed::create($request->type);
        }
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

        $request->update([
            'status' => RequestStatus::REJECTED,
            'checker_type' => $rejector->getMorphClass(),
            'checker_id' => $rejector->getKey(),
            'checked_at' => Carbon::now(),
            'remarks' => $remarks,
        ]);

        try {
            $this->executeCallbackHook($request, Hooks::PRE_REJECTION);

            return $request;
        } catch (Throwable $e) {
            $request->update([
                'status' => RequestStatus::FAILED,
                'exception' => (string) $e,
            ]);

            $onFailureCallBack = $this->getHook($request, Hooks::ON_FAILURE);

            if ($onFailureCallBack) {
                $onFailureCallBack($request, $e);
            }

            $this->app['events']->dispatch(new RequestFailed($request, $e));

            throw RequestCouldNotBeProcessed::create($e->getMessage(), $e);
        } finally {
            $this->executeCallbackHook($request, Hooks::POST_REJECTION);

            $this->app['events']->dispatch(new RequestRejected($request));
        }
    }
}
