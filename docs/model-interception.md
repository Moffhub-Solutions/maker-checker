# Model Interception

The `RequiresApproval` trait automatically intercepts Eloquent model events (create, update, delete) and redirects them through the maker-checker approval flow.

## Basic Setup

```php
use Moffhub\MakerChecker\Traits\RequiresApproval;

class Transaction extends Model
{
    use RequiresApproval;
}
```

Now all create, update, and delete operations on `Transaction` will require approval.

## Configuring Which Actions Require Approval

```php
class Transaction extends Model
{
    use RequiresApproval;

    // Only intercept these actions (default: all)
    protected static array $requiresApprovalFor = ['create', 'delete'];

    // Define approval requirements per action
    protected static array $approvalRequirements = [
        'create' => ['finance' => 1],
        'update' => ['finance' => 1],
        'delete' => ['finance' => 1, 'manager' => 1],
    ];
}
```

## Handling Intercepted Operations

When an operation is intercepted, `save()` or `delete()` returns `false`. Check for interception and retrieve the pending request:

```php
$transaction = new Transaction($data);
$saved = $transaction->save();

if (!$saved && Transaction::wasIntercepted()) {
    $request = Transaction::getInterceptedRequest();

    return response()->json([
        'message' => 'Pending approval',
        'request_id' => $request->id,
        'request_code' => $request->code,
    ], 202);
}

// Clear the intercepted state after handling
Transaction::clearInterceptedRequest();
```

## Exception Mode

If you prefer exceptions over return-value checking:

```php
Transaction::throwOnIntercept(true);

try {
    Transaction::create($data);
} catch (PendingApprovalException $e) {
    $request = $e->getRequest();
    return response()->json(['request' => $request->toArray()], 202);
}
```

## Bypassing Approval

For admin operations, seeders, or migrations where you need to skip approval:

```php
// Method 1: One-time bypass (resets after one operation)
Transaction::withoutApproval();
Transaction::create([...]); // Bypassed
Transaction::create([...]); // Requires approval again

// Method 2: Instance methods
$transaction = Transaction::createWithoutApproval([...]);
$transaction->updateWithoutApproval(['amount' => 500]);
$transaction->deleteWithoutApproval();

// Method 3: Callback (recommended for multiple operations)
Transaction::withoutApprovalDo(function () {
    Transaction::create([...]);
    Transaction::create([...]);
    // All operations inside are bypassed
});
```

## Setting the Maker

By default, the trait uses `auth()->user()` as the maker. Override when needed:

```php
// Set a specific user as the maker
Transaction::setApprovalMaker($adminUser);
Transaction::create([...]); // Uses $adminUser as maker

// Reset to use auth()->user() again
Transaction::setApprovalMaker(null);
```

## Checking Pending Approvals

```php
$transaction = Transaction::find(1);

// Check if there are pending approvals
$transaction->hasPendingApproval();                      // Any action
$transaction->hasPendingApproval(RequestType::DELETE);   // Specific action

// Get pending approval requests
$pending = $transaction->getPendingApprovals();
$pendingDeletes = $transaction->getPendingApprovals(RequestType::DELETE);
```

## Relationships

`RequiresApproval` only intercepts model events (create/update/delete).
Relationship pivot writes (`attach`, `detach`, `sync`, `syncWithoutDetaching`,
`toggle`, `updateExistingPivot`) and `associate` / `dissociate` bypass model
events entirely and are **not** caught by it.

### Explicit API

Available on any model using `RequiresApproval`. The relationship is not
changed; a pending request is returned:

```php
$request = $employee->requestRelation('compensations')
    ->madeBy($actingUser)            // optional, defaults to approval maker / auth
    ->attach($compensation, ['role' => 'lead']);
```

All operations are supported: `attach`, `detach`, `sync`,
`syncWithoutDetaching`, `toggle`, `updateExistingPivot`, `associate`,
`dissociate`. Optionally chain `->description(...)`, `->withApprovals([...])`,
`->forTeam($id)`.

### Transparent Interception

Add the `InterceptsRelationships` trait (alongside `RequiresApproval`) and
declare which relations to gate. Native syntax is then intercepted just like
`save()`/`delete()`:

```php
use Moffhub\MakerChecker\Traits\InterceptsRelationships;
use Moffhub\MakerChecker\Traits\RequiresApproval;

class Employee extends Model
{
    use RequiresApproval, InterceptsRelationships;

    // All operations on these relations require approval:
    protected static array $approvableRelations = ['compensations'];

    // Or restrict per relation:
    // protected static array $approvableRelations = [
    //     'compensations' => ['attach', 'detach', 'sync'],
    //     'manager'       => ['associate', 'dissociate'],
    // ];

    public function compensations(): BelongsToMany { /* ... */ }
}

$employee->compensations()->attach($compensation);

// Relationship methods have varying return types (attach() is void),
// so detect interception via wasIntercepted(), not the return value.
if (Employee::wasIntercepted()) {
    $request = Employee::getInterceptedRequest();
}
```

The same `withoutApproval()`, `withoutApprovalDo()`, `setApprovalMaker()`,
`throwOnIntercept()` and intercepted-request helpers documented above apply.
Relations not listed in `$approvableRelations`, operations not listed for a
relation, bypassed contexts, and operations with no resolvable maker
(seeders, migrations) pass straight through unchanged.

### Rollback

Relation requests are reversible. The relationship state is snapshotted when
the request is created, so `MakerChecker::rollback($request)` restores it
(undoing attach/detach/sync/toggle/updateExistingPivot, or the previous
foreign key for associate/dissociate).
