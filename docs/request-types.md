# Request Types

The package supports five request types: **Create**, **Update**, **Delete**, **Execute**, and **Relation**.

## Create

Submit a request to create a new model. The model is only created after the request is fully approved.

```php
MakerChecker::request()
    ->toCreate(Post::class, ['title' => 'New Post', 'content' => '...'])
    ->withApprovals(['admin' => 1])
    ->madeBy(auth()->user())
    ->save();
```

Or using the convenience method:

```php
$request = MakerChecker::create(Post::class, ['title' => 'New Post']);
```

## Update

Submit a request to update an existing model. The changes are only applied after approval.

```php
MakerChecker::request()
    ->toUpdate($post, ['title' => 'Updated Title'])
    ->madeBy(auth()->user())
    ->save();
```

Or:

```php
$request = MakerChecker::update($post, ['title' => 'Updated Title']);
```

## Delete

Submit a request to delete an existing model.

```php
MakerChecker::request()
    ->toDelete($post)
    ->withApprovals(['admin' => 2])
    ->madeBy(auth()->user())
    ->save();
```

Or:

```php
$request = MakerChecker::delete($post);
```

## Execute (Custom Actions)

For operations that don't map to a single model CRUD, create an executable class:

```php
use Moffhub\MakerChecker\Contracts\ExecutableRequest;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class TransferFunds extends ExecutableRequest
{
    public function execute(MakerCheckerRequest $request): void
    {
        $payload = $request->payload;

        BankService::transfer(
            from: $payload['from_account'],
            to: $payload['to_account'],
            amount: $payload['amount']
        );
    }

    public function uniqueBy(): array
    {
        return ['from_account', 'to_account', 'amount'];
    }

    public function beforeApproval(MakerCheckerRequest $request): void
    {
        // Validate accounts still exist
    }

    public function afterApproval(MakerCheckerRequest $request): void
    {
        // Send confirmation notification
    }

    public function onFailure(MakerCheckerRequest $request): void
    {
        // Handle failure
    }
}
```

Then submit the request:

```php
MakerChecker::request()
    ->toExecute(TransferFunds::class, [
        'from_account' => 'ACC001',
        'to_account' => 'ACC002',
        'amount' => 5000,
    ])
    ->withApprovals(['finance' => 1, 'manager' => 1])
    ->madeBy(auth()->user())
    ->save();
```

Or:

```php
$request = MakerChecker::execute(TransferFunds::class, ['amount' => 5000]);
```

## Relation (Nested Relationships)

Native relationship writes such as `attach`, `detach`, `sync`,
`syncWithoutDetaching`, `toggle`, `updateExistingPivot` (BelongsToMany /
MorphToMany) and `associate` / `dissociate` (BelongsTo) bypass Eloquent
model events, so the `create`/`update`/`delete` interception cannot see
them. Route them through a `Relation` request instead. The change is only
applied to the relationship once the request is approved.

```php
MakerChecker::request()
    ->toAttach($employee, 'compensations', $compensation, ['role' => 'lead'])
    ->withApprovals(['hr' => 1])
    ->madeBy(auth()->user())
    ->save();
```

Builder helpers: `toAttach`, `toDetach`, `toSync`, `toSyncWithoutDetaching`,
`toToggle`, `toUpdateExistingPivot`, `toAssociate`, `toDissociate`, or the
generic `toRelation($parent, $relation, $operation, $ids, $attributes)`.

On models using the `RequiresApproval` trait you can use the fluent helper
directly on the instance:

```php
$employee->requestRelation('compensations')
    ->madeBy(auth()->user())
    ->attach($compensation, ['role' => 'lead']);
```

To intercept native syntax transparently, see
[Model Interception › Relationships](model-interception.md#relationships).

Approval requirements for relation changes resolve from the `relation` key
in `global_approvals` / per-model `approvals` (config), or from
`makerCheckerApprovals()['relation']` on a `MakerCheckerConfigurable` model.
Relation requests are reversible: `MakerChecker::rollback($request)` restores
the previous relationship state captured when the request was created.

## Preventing Duplicate Requests

Use `uniqueBy()` to specify which payload fields determine uniqueness. If a pending request already exists with the same values for these fields, a `DuplicateRequestException` is thrown.

```php
MakerChecker::request()
    ->toCreate(User::class, ['email' => 'john@example.com', 'name' => 'John'])
    ->uniqueBy('email') // Prevent duplicate pending requests for the same email
    ->madeBy(auth()->user())
    ->save();
```

Enable duplicate checking globally:

```php
// config/maker-checker.php
'ensure_requests_are_unique' => true,
```

## Adding Descriptions

Descriptions make requests easier to identify in lists and notifications:

```php
MakerChecker::request()
    ->toCreate(Post::class, $data)
    ->description('Create blog post: My New Article')
    ->madeBy(auth()->user())
    ->save();

// Or with convenience methods
$request = MakerChecker::create(Post::class, $data, 'Create blog post: My New Article');
```

If no description is provided, one is auto-generated (e.g., "Create new Post").
