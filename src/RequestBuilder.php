<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Contracts\ExecutableRequest;
use Moffhub\MakerChecker\Enums\Hooks;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Exceptions\DuplicateRequestException;
use Moffhub\MakerChecker\Exceptions\InvalidRequestModelSet;
use Moffhub\MakerChecker\Exceptions\ModelCannotMakeRequests;
use Moffhub\MakerChecker\Exceptions\RequestCouldNotBeInitiated;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Relations\RelationOperation;
use Throwable;

class RequestBuilder
{
    private array $hooks = [];

    private array $uniqueIdentifiers = [];

    private bool $uniqueIdentifiersSet = false;

    private bool $approvalsSet = false;

    private array $originalValues = [];

    private MakerCheckerRequest $request;

    private readonly array $configData;

    private readonly ConfigResolver $configResolver;

    /**
     * @throws InvalidRequestModelSet
     */
    public function __construct(private Application $app)
    {
        $this->configData = $this->app['config']['maker-checker'];
        $this->configResolver = new ConfigResolver($this->configData);
        $this->request = $this->createNewPendingRequest();
    }

    /**
     * @throws InvalidRequestModelSet
     */
    private function createNewPendingRequest(): MakerCheckerRequest
    {
        $request = MakerCheckerServiceProvider::resolveRequestModel();

        $request->code = (string) Str::uuid();

        return $request;
    }

    /**
     * Add a description for the request.
     */
    public function description(string $description): self
    {
        $this->request->description = $description;

        return $this;
    }

    /**
     * Specify the user making the request.
     */
    public function madeBy(Model $maker): self
    {
        $this->assertModelCanMakeRequests($maker);

        $this->request->maker()->associate($maker);

        return $this;
    }

    private function assertModelCanMakeRequests(Model $requestor): void
    {
        $requestingModel = $requestor::class;
        $allowedRequestors = data_get($this->configData, 'whitelisted_models.maker');

        if (is_string($allowedRequestors)) {
            $allowedRequestors = [$allowedRequestors];
        }

        if (!is_array($allowedRequestors)) {
            $allowedRequestors = [];
        }

        if ($allowedRequestors !== [] && !in_array($requestingModel, $allowedRequestors)) {
            throw ModelCannotMakeRequests::create($requestingModel);
        }
    }

    /**
     * Commence initiation of a create request.
     *
     * @param  string  $model  The model class to create
     * @param  array  $payload  The data for creating the model
     * @param  array  $requiredApprovals  Optional approval requirements (will auto-resolve from config if empty)
     * @param  int|null  $teamId  Optional team ID for multi-tenant systems
     */
    public function toCreate(string $model, array $payload = [], array $requiredApprovals = [], ?int $teamId = null): self
    {
        $this->assertRequestTypeIsNotSet();

        if (!is_subclass_of($model, Model::class)) {
            throw new RequestCouldNotBeInitiated('Unrecognized model: '.$model);
        }

        $this->request->type = RequestType::CREATE;
        $this->request->subject_type = $model;
        $this->request->payload = $payload;
        $this->request->team_id = $teamId;

        // Handle approvals
        if ($requiredApprovals !== []) {
            $this->request->required_approvals = $requiredApprovals;
            $this->approvalsSet = true;
        }

        return $this;
    }

    private function assertRequestTypeIsNotSet(): void
    {
        if (isset($this->request->type)) {
            throw new RequestCouldNotBeInitiated('Cannot modify request type, a request type has already been provided.');
        }
    }

    /**
     * Commence initiation of an update request.
     *
     * @param  Model  $modelToUpdate  The model instance to update
     * @param  array  $requestedChanges  The changes to apply
     * @param  array  $requiredApprovals  Optional approval requirements (will auto-resolve from config if empty)
     * @param  int|null  $teamId  Optional team ID for multi-tenant systems
     */
    public function toUpdate(Model $modelToUpdate, array $requestedChanges, array $requiredApprovals = [], ?int $teamId = null): self
    {
        $this->assertRequestTypeIsNotSet();

        $this->request->type = RequestType::UPDATE;
        $this->request->subject()->associate($modelToUpdate);
        $this->request->payload = $requestedChanges;
        $this->request->team_id = $teamId;

        // Capture original values for the changed fields to support rollback
        $changedFields = array_keys($requestedChanges);
        $this->originalValues = Arr::only($modelToUpdate->getAttributes(), $changedFields);

        // Handle approvals
        if ($requiredApprovals !== []) {
            $this->request->required_approvals = $requiredApprovals;
            $this->approvalsSet = true;
        }

        return $this;
    }

    /**
     * Commence initiation of a delete request.
     *
     * @param  Model  $modelToDelete  The model instance to delete
     * @param  array  $requiredApprovals  Optional approval requirements (will auto-resolve from config if empty)
     * @param  int|null  $teamId  Optional team ID for multi-tenant systems
     */
    public function toDelete(Model $modelToDelete, array $requiredApprovals = [], ?int $teamId = null): self
    {
        $this->assertRequestTypeIsNotSet();

        $this->request->type = RequestType::DELETE;
        $this->request->payload = [];
        $this->request->team_id = $teamId;
        $this->request->subject()->associate($modelToDelete);

        // Handle approvals
        if ($requiredApprovals !== []) {
            $this->request->required_approvals = $requiredApprovals;
            $this->approvalsSet = true;
        }

        return $this;
    }

    /**
     * Commence initiation of an execute request.
     *
     * @param  string|Closure  $executableAction  The class to execute (must extend ExecutableRequest)
     * @param  array  $payload  The data for the execution
     * @param  array  $requiredApprovals  Optional approval requirements (will auto-resolve from config if empty)
     * @param  int|null  $teamId  Optional team ID for multi-tenant systems
     *
     * @throws Exception
     */
    public function toExecute(string|Closure $executableAction, array $payload = [], array $requiredApprovals = [], ?int $teamId = null): self
    {
        $this->assertRequestTypeIsNotSet();
        if ($executableAction instanceof Closure) {
            $executable = ($executableAction)($this->request, $payload);

        } else {
            $executable = $this->app->make($executableAction);

            if (!$executable instanceof ExecutableRequest) {
                throw new InvalidArgumentException(sprintf('The executable action must implement the %s interface.',
                    ExecutableRequest::class));
            }
        }

        $this->request->type = RequestType::EXECUTE;
        $this->request->executable = $executableAction;
        $this->request->payload = $payload;
        $this->request->team_id = $teamId;

        // Handle approvals
        if ($requiredApprovals !== []) {
            $this->request->required_approvals = $requiredApprovals;
            $this->approvalsSet = true;
        }

        // Handle unique identifiers from executable
        if (!$this->uniqueIdentifiersSet) {
            $this->uniqueIdentifiers = $executable->uniqueBy();
        }

        $this->setHooksFromExecutable($executable);

        return $this;
    }

    /**
     * Commence initiation of a relationship-change request.
     *
     * Covers pivot operations (attach, detach, sync, syncWithoutDetaching,
     * toggle, updateExistingPivot) and belongsTo operations (associate,
     * dissociate). The change is applied only once the request is approved.
     *
     * @param  Model  $parent  The model the relationship is defined on
     * @param  string  $relation  The relationship method name (e.g. 'compensations')
     * @param  string  $operation  One of the supported relation operations
     * @param  mixed  $ids  Related model(s)/id(s), or null (e.g. detach-all, dissociate)
     * @param  array<string, mixed>  $attributes  Pivot attributes (attach/updateExistingPivot)
     * @param  array  $requiredApprovals  Optional approval requirements
     * @param  int|null  $teamId  Optional team id for multi-tenant systems
     */
    public function toRelation(
        Model $parent,
        string $relation,
        string $operation,
        mixed $ids = null,
        array $attributes = [],
        array $requiredApprovals = [],
        ?int $teamId = null,
        bool $detaching = true,
        bool $touch = true,
    ): self {
        $this->assertRequestTypeIsNotSet();

        RelationOperation::assertSupported($operation);

        // Validates the relation exists and matches the operation kind.
        $resolved = RelationOperation::resolveRelation($parent, $relation, $operation);

        $relatedType = null;
        if ($operation === 'associate') {
            $relatedType = $ids instanceof Model
                ? $ids::class
                : $resolved->getRelated()::class;
        }

        $payload = [
            'relation' => $relation,
            'operation' => $operation,
            'ids' => RelationOperation::normalizeIds($ids),
            'attributes' => $attributes,
            'detaching' => $detaching,
            'touch' => $touch,
        ];

        if ($relatedType !== null) {
            $payload['related_type'] = $relatedType;
        }

        $this->request->type = RequestType::RELATION;
        $this->request->subject()->associate($parent);
        $this->request->payload = $payload;
        $this->request->team_id = $teamId;

        // Snapshot current relationship state so the change can be rolled back.
        $this->originalValues = RelationOperation::snapshot($parent, $relation, $operation);

        if ($requiredApprovals !== []) {
            $this->request->required_approvals = $requiredApprovals;
            $this->approvalsSet = true;
        }

        return $this;
    }

    /**
     * Initiate a request to attach related models to a BelongsToMany relation.
     */
    public function toAttach(Model $parent, string $relation, mixed $ids, array $attributes = []): self
    {
        return $this->toRelation($parent, $relation, 'attach', $ids, $attributes);
    }

    /**
     * Initiate a request to detach related models from a BelongsToMany relation.
     */
    public function toDetach(Model $parent, string $relation, mixed $ids = null): self
    {
        return $this->toRelation($parent, $relation, 'detach', $ids);
    }

    /**
     * Initiate a request to sync a BelongsToMany relation.
     */
    public function toSync(Model $parent, string $relation, mixed $ids, bool $detaching = true): self
    {
        return $this->toRelation($parent, $relation, 'sync', $ids, [], [], null, $detaching);
    }

    /**
     * Initiate a request to sync without detaching on a BelongsToMany relation.
     */
    public function toSyncWithoutDetaching(Model $parent, string $relation, mixed $ids): self
    {
        return $this->toRelation($parent, $relation, 'syncWithoutDetaching', $ids);
    }

    /**
     * Initiate a request to toggle a BelongsToMany relation.
     */
    public function toToggle(Model $parent, string $relation, mixed $ids): self
    {
        return $this->toRelation($parent, $relation, 'toggle', $ids);
    }

    /**
     * Initiate a request to update an existing pivot row.
     */
    public function toUpdateExistingPivot(Model $parent, string $relation, mixed $id, array $attributes): self
    {
        return $this->toRelation($parent, $relation, 'updateExistingPivot', $id, $attributes);
    }

    /**
     * Initiate a request to associate a BelongsTo relation.
     */
    public function toAssociate(Model $parent, string $relation, Model $related): self
    {
        return $this->toRelation($parent, $relation, 'associate', $related);
    }

    /**
     * Initiate a request to dissociate a BelongsTo relation.
     */
    public function toDissociate(Model $parent, string $relation): self
    {
        return $this->toRelation($parent, $relation, 'dissociate');
    }

    /**
     * Set the required approvals for this request.
     *
     * Supports two formats:
     * - Legacy: ['admin' => 2, 'manager' => 1] (role-based only)
     * - New: ['roles' => ['admin' => 1], 'users' => ['user@example.com']]
     *
     * @param  array<string, int>|array{roles?: array<string, int>, users?: array<string>}  $approvals
     */
    public function withApprovals(array $approvals): self
    {
        $this->request->required_approvals = $approvals;
        $this->approvalsSet = true;

        return $this;
    }

    /**
     * Require specific users to approve this request.
     *
     * Users can be specified by email or ID. These users must exist in the system
     * when the request is saved (validation is performed).
     *
     * @param  array<string>  $userIdentifiers  Array of user emails or IDs
     * @param  bool  $validateExistence  Whether to validate users exist (default: true)
     */
    public function requiringUsersToApprove(array $userIdentifiers, bool $validateExistence = true): self
    {
        $currentApprovals = $this->request->required_approvals ?? [];

        // Convert legacy format to new format if needed
        if (!isset($currentApprovals['roles']) && !isset($currentApprovals['users'])) {
            $currentApprovals = ['roles' => $currentApprovals, 'users' => []];
        }

        $currentApprovals['users'] = array_unique(array_merge(
            $currentApprovals['users'] ?? [],
            $userIdentifiers
        ));

        // Store whether to validate (explicitly set both true and false)
        $currentApprovals['_validate_users'] = $validateExistence;

        $this->request->required_approvals = $currentApprovals;
        $this->approvalsSet = true;

        return $this;
    }

    /**
     * Require approval from specific roles AND specific users.
     *
     * @param  array<string, int>  $roles  Role requirements, e.g., ['admin' => 1]
     * @param  array<string>  $users  User emails or IDs
     * @param  bool  $validateUsers  Whether to validate users exist (default: true)
     */
    public function withRoleAndUserApprovals(array $roles, array $users, bool $validateUsers = true): self
    {
        $approvals = [
            'roles' => $roles,
            'users' => array_unique($users),
        ];

        if ($validateUsers) {
            $approvals['_validate_users'] = true;
        }

        $this->request->required_approvals = $approvals;
        $this->approvalsSet = true;

        return $this;
    }

    /**
     * Set the approval mode for this request.
     *
     * - 'all' (default): ALL role thresholds must be met AND all required users must approve.
     * - 'any': Approval is granted if ANY single role meets its threshold OR ANY single required user approves.
     *
     * @param  string  $mode  'all' or 'any'
     */
    public function withApprovalMode(string $mode): self
    {
        if (!in_array($mode, ['all', 'any'], true)) {
            throw new InvalidArgumentException("Approval mode must be 'all' or 'any', got '{$mode}'.");
        }

        $currentApprovals = $this->request->required_approvals ?? [];

        // Convert legacy format to new format if needed
        if (!isset($currentApprovals['roles']) && !isset($currentApprovals['users']) && !isset($currentApprovals['mode'])) {
            $currentApprovals = ['roles' => $currentApprovals];
        }

        $currentApprovals['mode'] = $mode;
        $this->request->required_approvals = $currentApprovals;
        $this->approvalsSet = true;

        return $this;
    }

    /**
     * Set approval requirements with OR logic.
     *
     * The request will be approved when ANY one of the specified roles meets its threshold
     * OR ANY one of the specified users approves.
     *
     * @param  array<string, int>|array{roles?: array<string, int>, users?: array<string>}  $approvals
     */
    public function withAnyApproval(array $approvals): self
    {
        // Normalize to new format if legacy
        if (!isset($approvals['roles']) && !isset($approvals['users'])) {
            $approvals = ['roles' => $approvals];
        }

        $approvals['mode'] = 'any';
        $this->request->required_approvals = $approvals;
        $this->approvalsSet = true;

        return $this;
    }

    /**
     * Require approval from any one of the specified roles OR specific users (OR logic).
     *
     * @param  array<string, int>  $roles  Role requirements, e.g., ['admin' => 1, 'manager' => 1]
     * @param  array<string>  $users  User emails or IDs
     * @param  bool  $validateUsers  Whether to validate users exist (default: true)
     */
    public function withAnyRoleOrUserApproval(array $roles, array $users = [], bool $validateUsers = true): self
    {
        $approvals = [
            'roles' => $roles,
            'users' => array_unique($users),
            'mode' => 'any',
        ];

        if ($validateUsers && !empty($users)) {
            $approvals['_validate_users'] = true;
        }

        $this->request->required_approvals = $approvals;
        $this->approvalsSet = true;

        return $this;
    }

    /**
     * Provide the fields to check on the request payload for determining request uniqueness.
     * If not provided, the package will check against the entire payload.
     *
     * @param  string  ...$fields  Field names from the payload
     */
    public function uniqueBy(string ...$fields): self
    {
        $this->uniqueIdentifiers = $fields;
        $this->uniqueIdentifiersSet = true;

        return $this;
    }

    /**
     * @throws Exception
     */
    private function setHooksFromExecutable(ExecutableRequest $executable): void
    {
        foreach (Hooks::executableHooks() as $hook) {
            $closure = $hook->hookMethods();
            $this->setHook($hook, $executable->$closure(...));
        }
    }

    /**
     * @throws Exception
     */
    private function setHook(Hooks $hookName, Closure $callback): void
    {
        if (!in_array($hookName, Hooks::cases())) {
            throw new Exception('Invalid hook passed.');
        }

        $this->hooks[$hookName->value] = new SerializableClosure($callback);
    }

    /**
     * Perform custom actions on the underlying request.
     */
    public function tap(Closure $callback): self
    {
        $callback($this->request);

        return $this;
    }

    /**
     * Define a callback to be executed before a request is marked as approved.
     *
     * @throws Exception
     */
    public function beforeApproval(Closure $callback): self
    {
        $this->setHook(Hooks::PRE_APPROVAL, $callback);

        return $this;
    }

    /**
     * Define a callback to be executed after a request is fulfilled.
     *
     * @throws Exception
     */
    public function afterApproval(Closure $callback): self
    {
        $this->setHook(Hooks::POST_APPROVAL, $callback);

        return $this;
    }

    /**
     * Define a callback to be executed before a request is marked as rejected.
     *
     * @throws Exception
     */
    public function beforeRejection(Closure $callback): self
    {
        $this->setHook(Hooks::PRE_REJECTION, $callback);

        return $this;
    }

    /**
     * Define a callback to be executed after a request is rejected.
     *
     * @throws Exception
     */
    public function afterRejection(Closure $callback): self
    {
        $this->setHook(Hooks::POST_REJECTION, $callback);

        return $this;
    }

    /**
     * Define a callback to be executed in the event of a failure while fulfilling the request.
     *
     * @throws Exception
     */
    public function onFailure(Closure $callback): self
    {
        $this->setHook(Hooks::ON_FAILURE, $callback);

        return $this;
    }

    /**
     * Persist the request into the data store.
     *
     * @throws InvalidRequestModelSet
     */
    public function save(): MakerCheckerRequest
    {
        $request = $this->request;

        // Get executable as string (for config resolution)
        $executableClass = is_string($request->executable) ? $request->executable : null;

        // Auto-resolve approvals from config if not explicitly set
        // Pass payload for conditional config matching
        if (!$this->approvalsSet && $request->subject_type) {
            $approvals = $this->configResolver->getApprovals(
                $request->subject_type,
                $request->type,
                $executableClass,
                $request->team_id,
                $request->payload ?? []
            );
            if ($approvals !== []) {
                $request->required_approvals = $approvals;
            }
        }

        // Auto-resolve unique identifiers from config if not explicitly set
        // Pass payload for conditional config matching
        if (!$this->uniqueIdentifiersSet && $request->subject_type) {
            $uniqueFields = $this->configResolver->getUniqueFields(
                $request->subject_type,
                $request->type,
                $executableClass,
                $request->team_id,
                $request->payload ?? []
            );
            if ($uniqueFields !== []) {
                $this->uniqueIdentifiers = $uniqueFields;
            }
        }

        // Auto-generate description if not set
        if (!isset($request->description)) {
            if ($request->subject_type) {
                $request->description = $this->configResolver->getDescription(
                    $request->subject_type,
                    $request->type,
                    $request->payload ?? []
                );
            } else {
                $request->description = "New {$request->type->display()} request";
            }
        }

        $request->status = RequestStatus::PENDING;
        $request->metadata = $this->generateMetadata();
        $request->made_at = Carbon::now();

        if (data_get($this->configData, 'ensure_requests_are_unique')) {
            $this->assertRequestIsUnique($request);
        }

        // Validate required users exist in the system
        $this->validateRequiredUsersExist($request);

        try {
            $request->saveOrFail();

            $this->app['events']->dispatch(RequestInitiated::fromRequest($request));

            return $request;
        } catch (Throwable $e) {
            throw new RequestCouldNotBeInitiated("Error initiating request: {$e->getMessage()}", 0, $e);
        } finally {
            $this->request = $this->createNewPendingRequest(); // reset it back to how it was
            $this->hooks = [];
            $this->uniqueIdentifiers = [];
            $this->uniqueIdentifiersSet = false;
            $this->approvalsSet = false;
            $this->originalValues = [];
        }
    }

    private function generateMetadata(): array
    {
        $metadata = [
            'hooks' => $this->hooks,
        ];

        if ($this->originalValues !== []) {
            $metadata['original_values'] = $this->originalValues;
        }

        return $metadata;
    }

    /**
     * Assert that there's no pending request with the same properties as this new request.
     *
     * @throws InvalidRequestModelSet
     */
    protected function assertRequestIsUnique(MakerCheckerRequest $request): void
    {
        if ($request->payload === null) {
            return;
        }
        $requestModel = MakerCheckerServiceProvider::resolveRequestModel();

        $baseQuery = $requestModel::query()
            ->whereIn('status', [RequestStatus::PENDING, RequestStatus::PARTIALLY_APPROVED])
            ->where('type', $request->type)
            ->where('executable', $request->executable)
            ->where('subject_type', $request->subject_type)
            ->where('subject_id', $request->subject_id);

        $fieldsToCheck = $this->uniqueIdentifiers === [] || empty(Arr::only($request->payload, $this->uniqueIdentifiers))
            ? $request->payload
            : Arr::only($request->payload, $this->uniqueIdentifiers);

        if ($fieldsToCheck) {
            foreach ($fieldsToCheck as $key => $value) {
                $baseQuery->where("payload->$key", $value);
            }
        }

        if ($baseQuery->exists()) {
            throw DuplicateRequestException::create($request->type);
        }
    }

    /**
     * Validate that all required users exist in the system.
     *
     * @throws RequestCouldNotBeInitiated
     */
    protected function validateRequiredUsersExist(MakerCheckerRequest $request): void
    {
        $requiredApprovals = $request->required_approvals ?? [];

        // Check if there are users to validate
        if (!isset($requiredApprovals['users']) || empty($requiredApprovals['users'])) {
            return;
        }

        // Check if validation is enabled (default: true for user approvals)
        $shouldValidate = $requiredApprovals['_validate_users'] ?? true;

        if (!$shouldValidate) {
            return;
        }

        $users = $requiredApprovals['users'];

        /** @var ApproverResolver $resolver */
        $resolver = $this->app->make(ApproverResolver::class);

        $missingUsers = $resolver->validateUsersExist($users);

        if (!empty($missingUsers)) {
            throw new RequestCouldNotBeInitiated(
                'The following required approvers do not exist in the system: '.implode(', ', $missingUsers)
            );
        }

        // Remove the validation flag before saving
        if (isset($requiredApprovals['_validate_users'])) {
            unset($requiredApprovals['_validate_users']);
            $request->required_approvals = $requiredApprovals;
        }
    }
}
