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
