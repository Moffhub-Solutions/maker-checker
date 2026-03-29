# Maker-Checker for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/moffhub/maker-checker.svg?style=flat-square)](https://packagist.org/packages/moffhub/maker-checker)
[![Total Downloads](https://img.shields.io/packagist/dt/moffhub/maker-checker.svg?style=flat-square)](https://packagist.org/packages/moffhub/maker-checker)
[![License](https://img.shields.io/packagist/l/moffhub/maker-checker.svg?style=flat-square)](https://packagist.org/packages/moffhub/maker-checker)
[![PHP Version](https://img.shields.io/packagist/php-v/moffhub/maker-checker.svg?style=flat-square)](https://packagist.org/packages/moffhub/maker-checker)

The most feature-complete **maker-checker (four-eyes principle)** approval workflow package for Laravel. Add multi-level approval requirements to any Eloquent model with a single trait, or use the full-featured API for complex enterprise workflows.

Unlike simpler approval packages, this supports **multi-role approvals**, a **conditional rules engine**, **approval delegation**, **bulk operations**, **audit trail with export**, **reminders & escalation**, and **auto-intercept via Eloquent model events** -- all out of the box.

## Why This Package?

| Feature | moffhub/maker-checker | Others |
|---------|:---------------------:|:------:|
| Auto-intercept via trait | ✅ | Some |
| Multi-role approvals (2 admins + 1 manager) | ✅ | Rare |
| User-specific approvals | ✅ | ❌ |
| Conditional rules engine (if amount > 50K...) | ✅ | ❌ |
| Database-driven config (change rules at runtime) | ✅ | ❌ |
| Execute arbitrary actions (not just CRUD) | ✅ | ❌ |
| Approval delegation with expiry | ✅ | ❌ |
| Bulk approve endpoint | ✅ | ❌ |
| Reminders & escalation | ✅ | ❌ |
| Audit trail + CSV/JSON export | ✅ | Rare |
| Race condition safe (pessimistic locking) | ✅ | ❌ |
| REST API included | ✅ | ❌ |
| 360+ tests | ✅ | Varies |

## Features

- **Auto-intercept** - Add `RequiresApproval` trait to any model; create/update/delete are intercepted automatically
- **Multi-role approvals** - Require approvals from specific roles (e.g., 2 admins + 1 manager)
- **User-specific approvals** - Require specific people to approve by email or ID
- **Conditional rules engine** - Different approval rules based on payload (e.g., amount > 50,000 needs extra approval)
- **CRUD + Execute** - Built-in support for Create, Update, Delete, and custom Execute operations
- **Flexible configuration** - Configure via file, database, model interfaces, or runtime API
- **Multi-tenancy support** - Team/company scoping for requests
- **API ready** - RESTful API endpoints for managing requests, configs, delegations, and audits
- **Bulk operations** - Approve multiple requests in one call
- **Delegation** - Delegate approval authority to another user with optional expiry
- **Hooks & callbacks** - Execute custom logic before/after approval or rejection
- **Reminders & escalation** - Auto-remind approvers; escalate after configurable delay
- **Request expiration** - Auto-expire pending requests after a configurable time
- **Audit trail** - Full audit log with CSV/JSON export endpoint
- **Notifications** - Built-in email/database notifications with sequential approval support
- **Race condition safe** - Pessimistic locking prevents double-approval bugs

## Installation

Install the package via Composer:

```bash
composer require moffhub/maker-checker
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=maker-checker-migrations
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=maker-checker-config
```

## Quick Start

### Option A: Automatic Model Event Interception (Recommended)

The simplest way to add maker-checker to your models is using the `RequiresApproval` trait. This automatically intercepts create, update, and delete operations:

```php
use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Traits\RequiresApproval;

class Post extends Model
{
    use RequiresApproval;

    // Optionally specify which actions require approval
    protected static array $requiresApprovalFor = ['create', 'delete'];

    // Optionally define approval requirements
    protected static array $approvalRequirements = [
        'create' => ['editor' => 1],
        'delete' => ['admin' => 2],
    ];
}
```

Now when you try to create or delete a Post, the operation returns `false` and a pending approval request is created:

```php
$post = new Post(['title' => 'My Post', 'user_id' => auth()->id()]);
$saved = $post->save();

if (!$saved && Post::wasIntercepted()) {
    $request = Post::getInterceptedRequest();

    return response()->json([
        'message' => 'Your request has been submitted for approval.',
        'request_id' => $request->id,
        'request_code' => $request->code,
    ], 202); // HTTP 202 Accepted
}

// To bypass approval (for admin operations, seeders, etc.):
$post = Post::createWithoutApproval(['title' => 'Direct Create']);

// Or use the callback method:
Post::withoutApprovalDo(function () {
    Post::create(['title' => 'Also bypassed']);
});
```

### Option B: Convenience Methods (Simple API)

Use the `MakerChecker` facade with convenience methods that auto-inject the authenticated user:

```php
use Moffhub\MakerChecker\Facades\MakerChecker;

// Create a request (auto-injects auth user as maker)
$request = MakerChecker::create(Post::class, ['title' => 'My Post']);

// With custom description
$request = MakerChecker::create(Post::class, ['title' => 'My Post'], 'Create a new blog post');

// Update a model
$request = MakerChecker::update($post, ['title' => 'Updated Title']);

// Delete a model
$request = MakerChecker::delete($post);

// Execute a custom action
$request = MakerChecker::execute(TransferFunds::class, ['amount' => 5000]);
```

Approve, reject, or cancel requests (also auto-injects auth user):

```php
// Approve (uses authenticated user)
MakerChecker::approve($request);
MakerChecker::approve($request, null, 'admin');           // With role
MakerChecker::approve($request, null, 'admin', 'LGTM');  // With role and remarks

// Or with explicit user
MakerChecker::approve($request, $approver, 'admin');

// Reject
MakerChecker::reject($request);
MakerChecker::reject($request, null, 'Missing information');

// Cancel (only maker can cancel)
MakerChecker::cancel($request);
```

You can also call these methods directly on the request model:

```php
$request->approve();                          // Uses auth user
$request->approve(null, 'admin');             // With role
$request->approve($user, 'admin', 'Approved'); // Explicit user

$request->reject(null, 'Not approved');
$request->cancel();
```

### Option C: Request Builder (Full Control)

For advanced usage with hooks and custom configuration:

```php
use Moffhub\MakerChecker\Facades\MakerChecker;

$request = MakerChecker::request()
    ->toCreate(Post::class, ['title' => 'My Post'])
    ->madeBy(auth()->user())
    ->description('Create a new blog post')
    ->withApprovals(['editor' => 1, 'admin' => 1])
    ->beforeApproval(fn($r) => Log::info('Approving...'))
    ->afterApproval(fn($r) => Notification::send(...))
    ->save();
```

### 1. Implement the User Contract

Add the `MakerCheckerUserContract` interface to your User model:

```php
use Moffhub\MakerChecker\Contracts\MakerCheckerUserContract;

class User extends Authenticatable implements MakerCheckerUserContract
{
    public function hasMakerCheckerPermission(string $permission): bool
    {
        return $this->hasPermission($permission); // Your permission logic
    }

    public function getMakerCheckerTeamId(): ?int
    {
        return $this->team_id; // For multi-tenancy, or null
    }

    public function getMakerCheckerRole(): ?string
    {
        return $this->role; // e.g., 'admin', 'manager'
    }

    public function getMakerCheckerEmail(): ?string
    {
        return $this->email;
    }
}
```

### 2. Create a Pending Request

Use the `MakerChecker` facade to create approval requests:

```php
use Moffhub\MakerChecker\Facades\MakerChecker;
use App\Models\Post;

// Create request for a new Post
$request = MakerChecker::request()
    ->toCreate(Post::class, [
        'title' => 'My New Post',
        'content' => 'Post content here...',
        'user_id' => auth()->id(),
    ])
    ->madeBy(auth()->user())
    ->description('Create a new blog post')
    ->save();
```

### 3. Approve or Reject

```php
use Moffhub\MakerChecker\Facades\MakerChecker;

// Simple - uses authenticated user automatically
MakerChecker::approve($request);
MakerChecker::reject($request, null, 'Missing required information');

// With role (for multi-role approvals)
MakerChecker::approve($request, null, 'admin', 'Looks good!');

// With explicit user
MakerChecker::approve($request, $approverUser, 'admin', 'Looks good!');

// Or call directly on the request model
$request->approve();
$request->approve(null, 'admin');
$request->reject(null, 'Not approved');
$request->cancel(); // Only maker can cancel
```

## Request Types

### Create

```php
MakerChecker::request()
    ->toCreate(Post::class, ['title' => 'New Post', 'content' => '...'])
    ->withApprovals(['admin' => 1])
    ->madeBy(auth()->user())
    ->save();
```

### Update

```php
MakerChecker::request()
    ->toUpdate($post, ['title' => 'Updated Title'])
    ->madeBy(auth()->user())
    ->save();
```

### Delete

```php
MakerChecker::request()
    ->toDelete($post)
    ->withApprovals(['admin' => 2]) // Require 2 admin approvals
    ->madeBy(auth()->user())
    ->save();
```

### Execute (Custom Actions)

For complex operations, create an executable class:

```php
use Moffhub\MakerChecker\Contracts\ExecutableRequest;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class TransferFunds extends ExecutableRequest
{
    public function execute(MakerCheckerRequest $request): void
    {
        $payload = $request->payload;

        // Perform the transfer
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
        // Send notification
    }

    public function onFailure(MakerCheckerRequest $request): void
    {
        // Handle failure
    }
}
```

Then create the request:

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

## Multi-Role Approvals

Require approvals from multiple roles:

```php
MakerChecker::request()
    ->toCreate(User::class, $userData)
    ->withApprovals([
        'hr' => 1,        // 1 HR approval
        'admin' => 2,     // 2 Admin approvals
        'manager' => 1,   // 1 Manager approval
    ])
    ->madeBy(auth()->user())
    ->save();
```

Check approval status:

```php
$request->getApprovalCount();        // Total approvals received
$request->getPendingRoles();         // ['admin' => 1, ...] remaining
$request->hasMetApprovalThreshold(); // true/false
```

## User-Specific Approvals

In addition to role-based approvals, you can require specific users to approve a request. This is useful when you need approval from a particular person regardless of their role.

### Requiring Specific Users

Specify users by email or ID:

```php
// Require approval from a specific user by email
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->requiringUsersToApprove(['cfo@company.com'])
    ->madeBy(auth()->user())
    ->save();

// Require approval from multiple specific users
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->requiringUsersToApprove(['cfo@company.com', 'ceo@company.com'])
    ->madeBy(auth()->user())
    ->save();

// Require approval from user by ID
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->requiringUsersToApprove([(string) $cfoUser->id])
    ->madeBy(auth()->user())
    ->save();
```

### Combining Roles and Users

Require both role-based and user-specific approvals:

```php
// Requires 1 admin approval AND approval from the CFO
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->withRoleAndUserApprovals(
        roles: ['admin' => 1],
        users: ['cfo@company.com']
    )
    ->madeBy(auth()->user())
    ->save();
```

### User Validation

By default, the package validates that all specified users exist in the system before creating the request:

```php
// This will throw an exception if the user doesn't exist
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->requiringUsersToApprove(['nonexistent@company.com'])
    ->madeBy(auth()->user())
    ->save();
// Throws: RequestCouldNotBeInitiated

// Disable validation if needed (not recommended)
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->requiringUsersToApprove(['future@company.com'], validateExistence: false)
    ->madeBy(auth()->user())
    ->save();
```

### Checking Pending Users

```php
$request->requiresUserApprovals();   // true if users are required
$request->getPendingUsers();         // ['cfo@company.com', ...] remaining
```

### Approval Flow

When a user-specific approval is required, only the specified users can approve:

```php
$request = MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->requiringUsersToApprove(['cfo@company.com'])
    ->madeBy(auth()->user())
    ->save();

// Only the CFO can approve - other users will get an error
MakerChecker::approve($request, $cfoUser, 'user'); // Works
MakerChecker::approve($request, $otherUser);       // Throws exception
```

## Configuration

### Model-Based Configuration

Implement `MakerCheckerConfigurable` on your models:

```php
use Moffhub\MakerChecker\Contracts\MakerCheckerConfigurable;
use Moffhub\MakerChecker\Enums\RequestType;

class Post extends Model implements MakerCheckerConfigurable
{
    public static function makerCheckerApprovals(): array
    {
        return [
            'create' => ['editor' => 1],
            'update' => ['editor' => 1],
            'delete' => ['admin' => 1, 'editor' => 1],
        ];
    }

    public static function makerCheckerUniqueFields(): array
    {
        return [
            'create' => ['title', 'slug'],
        ];
    }

    public static function requiresMakerChecker(RequestType $action): bool
    {
        // Only require approval for delete
        return $action === RequestType::DELETE;
    }

    public static function makerCheckerDescription(RequestType $action, array $payload): string
    {
        return match($action) {
            RequestType::CREATE => "Create post: {$payload['title']}",
            RequestType::UPDATE => "Update post",
            RequestType::DELETE => "Delete post",
            default => "Post operation",
        };
    }
}
```

### File-Based Configuration

Configure in `config/maker-checker.php`:

```php
'models' => [
    App\Models\User::class => [
        'approvals' => [
            'create' => ['hr' => 1, 'admin' => 1],
            'update' => ['admin' => 1],
            'delete' => ['admin' => 2],
        ],
        'unique_fields' => [
            'create' => ['email'],
        ],
        'required_for' => ['create', 'delete'],
    ],
],

'executables' => [
    App\MakerChecker\TransferFunds::class => [
        'approvals' => ['finance' => 1, 'manager' => 1],
        'unique_fields' => ['from_account', 'to_account', 'amount'],
    ],
],

'global_approvals' => [
    'create' => ['admin' => 1],
    'update' => ['admin' => 1],
    'delete' => ['admin' => 2],
    'execute' => ['admin' => 1],
],
```

### Database-Driven Configuration

Enable the database driver for dynamic configuration:

```php
// config/maker-checker.php
'config_driver' => 'database',
```

Manage configs via API or programmatically:

```php
use Moffhub\MakerChecker\Models\MakerCheckerConfig;

// Create with role-based approvals
MakerCheckerConfig::create([
    'configurable_type' => Post::class,
    'action' => 'delete',
    'approvals' => [
        'roles' => ['admin' => 2],
    ],
    'is_active' => true,
]);

// Create with both role and user approvals
MakerCheckerConfig::create([
    'configurable_type' => Contract::class,
    'action' => 'create',
    'approvals' => [
        'roles' => ['admin' => 1, 'legal' => 1],
        'users' => ['cfo@company.com', 'ceo@company.com'],
    ],
    'description' => 'High-value contracts require CFO and CEO approval',
    'is_active' => true,
]);

// Create with user-only approvals
MakerCheckerConfig::create([
    'configurable_type' => Payment::class,
    'action' => 'create',
    'approvals' => [
        'users' => ['finance@company.com'],
    ],
    'is_active' => true,
]);
```

#### Configuration API

Create configuration via API:

```http
POST /api/maker-checker/configs
Content-Type: application/json

{
    "configurable_type": "App\\Models\\Contract",
    "action": "create",
    "approvals": {
        "roles": {
            "admin": 1,
            "legal": 1
        },
        "users": [
            "cfo@company.com",
            "ceo@company.com"
        ]
    },
    "description": "Contract creation approval workflow"
}
```

Response:

```json
{
    "message": "Configuration created successfully",
    "data": {
        "id": 1,
        "configurable_type": "App\\Models\\Contract",
        "configurable_name": "Contract",
        "action": "create",
        "action_label": "Create",
        "approvals": {
            "roles": {"admin": 1, "legal": 1},
            "users": ["cfo@company.com", "ceo@company.com"]
        },
        "role_approvals": {"admin": 1, "legal": 1},
        "user_approvals": ["cfo@company.com", "ceo@company.com"],
        "requires_user_approvals": true,
        "is_active": true
    }
}
```

Update configuration:

```http
PUT /api/maker-checker/configs/1
Content-Type: application/json

{
    "approvals": {
        "roles": {"admin": 2},
        "users": ["cfo@company.com"]
    }
}
```

#### UI Mockup

A sample UI mockup for the configuration management interface is available at `docs/ui-mockup.html`. Open it in a browser to see how the frontend could interact with these APIs.

## API Endpoints

The package provides RESTful API endpoints (prefix configurable):

### Requests

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/maker-checker/requests` | List all requests |
| GET | `/api/maker-checker/requests/{id}` | Get request details |
| GET | `/api/maker-checker/requests/{id}/approvals` | Get approval status |
| POST | `/api/maker-checker/requests/{id}/approve` | Approve a request |
| POST | `/api/maker-checker/requests/{id}/reject` | Reject a request |
| POST | `/api/maker-checker/requests/{id}/cancel` | Cancel own request |
| GET | `/api/maker-checker/requests/statistics` | Get request statistics |
| GET | `/api/maker-checker/requests/statuses` | List available statuses |

### Query Parameters

```
GET /api/maker-checker/requests?status=pending&type=create&team_id=1
```

### Configs (database driver)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/maker-checker/configs` | List all configs |
| POST | `/api/maker-checker/configs` | Create config |
| GET | `/api/maker-checker/configs/{id}` | Get config |
| PUT | `/api/maker-checker/configs/{id}` | Update config |
| DELETE | `/api/maker-checker/configs/{id}` | Delete config |
| POST | `/api/maker-checker/configs/{id}/enable` | Enable config |
| POST | `/api/maker-checker/configs/{id}/disable` | Disable config |
| GET | `/api/maker-checker/configs/export` | Export all configs |
| POST | `/api/maker-checker/configs/import` | Import configs |

### Route Configuration

```php
// config/maker-checker.php
'routes' => [
    'enabled' => true,
    'prefix' => 'api',
    'middleware' => ['api', 'auth:sanctum'],
],
```

To customize routes, publish them:

```bash
php artisan vendor:publish --tag=maker-checker-routes
```

## Request Statuses

| Status | Description |
|--------|-------------|
| `pending` | Awaiting first approval |
| `partially_approved` | Has some approvals but not all required |
| `approved` | Fully approved and executed |
| `rejected` | Rejected by a checker |
| `cancelled` | Cancelled by the maker |
| `expired` | Expired after timeout |
| `failed` | Execution failed |

## Hooks

Add hooks to the request builder:

```php
MakerChecker::request()
    ->toCreate(Post::class, $data)
    ->beforeApproval(function ($request) {
        Log::info('About to approve', ['id' => $request->id]);
    })
    ->afterApproval(function ($request) {
        Notification::send($request->maker, new RequestApproved($request));
    })
    ->beforeRejection(function ($request) {
        // Cleanup logic
    })
    ->afterRejection(function ($request) {
        Notification::send($request->maker, new RequestRejected($request));
    })
    ->onFailure(function ($request) {
        Log::error('Request failed', ['id' => $request->id]);
    })
    ->madeBy(auth()->user())
    ->save();
```

## Automatic Model Interception

The `RequiresApproval` trait provides automatic interception of Eloquent model events. When added to a model, create/update/delete operations return `false` and create a pending approval request instead of executing immediately.

### Basic Usage

```php
use Moffhub\MakerChecker\Traits\RequiresApproval;

class Transaction extends Model
{
    use RequiresApproval;
}
```

### Configuration via Properties

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

### Bypassing Approval

```php
// Method 1: Static method (resets after one operation)
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

### Handling Interception

When an operation is intercepted, the model's `save()` or `delete()` returns `false`. Check if it was intercepted and get the pending request:

```php
$transaction = new Transaction($data);
$saved = $transaction->save();

if (!$saved && Transaction::wasIntercepted()) {
    $request = Transaction::getInterceptedRequest();

    return response()->json([
        'message' => 'Pending approval',
        'request_id' => $request->id,
        'request_code' => $request->code,
    ], 202); // HTTP 202 Accepted
}

// Clear the intercepted state after handling
Transaction::clearInterceptedRequest();
```

### Exception Mode (Optional)

If you prefer exception-based handling:

```php
// Enable exception mode
Transaction::throwOnIntercept(true);

try {
    $transaction = Transaction::create($data);
} catch (PendingApprovalException $e) {
    $request = $e->getRequest();
    return response()->json(['request' => $request->toArray()], 202);
}
```

### Checking Pending Approvals

```php
$transaction = Transaction::find(1);

// Check if there are pending approvals
$transaction->hasPendingApproval(); // Any action
$transaction->hasPendingApproval(RequestType::DELETE); // Specific action

// Get pending approval requests
$pending = $transaction->getPendingApprovals();
$pendingDeletes = $transaction->getPendingApprovals(RequestType::DELETE);
```

### Setting the Maker

By default, the trait uses `auth()->user()` as the maker. You can override this:

```php
// Set a specific user as the maker
Transaction::setApprovalMaker($adminUser);

// Operations will use $adminUser as the maker
Transaction::create([...]);

// Reset to use auth()->user() again
Transaction::setApprovalMaker(null);
```

## Visibility Scoping

Query requests visible to a user:

```php
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

// Get requests visible to current user
$requests = MakerCheckerRequest::visibleTo(auth()->user())->get();

// Users with 'view_any_permission' see all requests
// Others see only their own or their team's requests
```

## Expiring Requests

Enable automatic expiration:

```php
// config/maker-checker.php
'request_expiration_in_minutes' => 1440, // 24 hours
```

Run the expiration command (add to scheduler):

```php
// app/Console/Kernel.php
$schedule->command('maker-checker:expire-requests')->hourly();
```

## Notifications

The package can automatically notify approvers when a new request is pending, and notify makers when their request is approved or rejected.

### Enabling Notifications

```php
// config/maker-checker.php
'notifications' => [
    'enabled' => true,
    'channels' => ['mail', 'database'], // Notification channels
    'notify_maker' => true,              // Notify maker of approval/rejection
    'sequential' => false,               // See "Sequential Notifications" below
    'user_model' => App\Models\User::class,
    'role_attribute' => 'role',          // Attribute containing user's role
],
```

### Finding Approvers by Role

The package uses an `ApproverResolver` to find users who can approve requests. The default resolver queries users by role attribute:

```php
// Default behavior: finds users where role = 'admin'
// When request requires ['admin' => 2], finds all users with role 'admin'
```

For more complex scenarios (Spatie permissions, team-based roles, etc.), implement your own resolver:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class CustomApproverResolver implements ApproverResolver
{
    public function getApproversForRole(MakerCheckerRequest $request, string $role): Collection
    {
        // Custom logic: Spatie permissions, team filtering, etc.
        return User::role($role)
            ->where('team_id', $request->team_id)
            ->where('id', '!=', $request->maker_id)
            ->get();
    }

    public function getAllApprovers(MakerCheckerRequest $request): Collection
    {
        // Get all users who can approve any role or are specifically required
        $requiredApprovals = $request->required_approvals ?? [];
        $roles = $requiredApprovals['roles'] ?? $requiredApprovals;
        $users = $requiredApprovals['users'] ?? [];

        $approvers = User::role(array_keys($roles))->get();

        if (!empty($users)) {
            $specificUsers = $this->getApproversByIdentifier($request, $users);
            $approvers = $approvers->merge($specificUsers)->unique('id');
        }

        return $approvers;
    }

    public function getApproversByIdentifier(MakerCheckerRequest $request, array $userIdentifiers): Collection
    {
        return User::whereIn('email', $userIdentifiers)
            ->orWhereIn('id', $userIdentifiers)
            ->where('id', '!=', $request->maker_id)
            ->get();
    }

    public function getApproverByIdentifier(string $identifier): ?Model
    {
        return User::where('email', $identifier)
            ->orWhere('id', $identifier)
            ->first();
    }

    public function userExists(string $identifier): bool
    {
        return $this->getApproverByIdentifier($identifier) !== null;
    }

    public function validateUsersExist(array $userIdentifiers): array
    {
        return array_filter($userIdentifiers, fn($id) => !$this->userExists($id));
    }
}

// Register in AppServiceProvider
$this->app->bind(ApproverResolver::class, CustomApproverResolver::class);
```

### Sequential Notifications

By default, all required roles are notified at once. Enable sequential mode to notify roles one at a time:

```php
'notifications' => [
    'sequential' => true,
],
```

In sequential mode:
1. First role is notified when request is created
2. After that role approves, next role is notified
3. Use `MakerChecker::notifyNextApprovers($request)` to manually trigger next notification

```php
// After partial approval, notify next approvers
MakerChecker::approve($request, $user, 'editor');

if ($request->isPartiallyApproved()) {
    MakerChecker::notifyNextApprovers($request);
}
```

### Custom Notification Classes

Override the default notifications with your own:

```php
// config/maker-checker.php
'notifications' => [
    'pending_notification' => App\Notifications\CustomPendingNotification::class,
    'approved_notification' => App\Notifications\CustomApprovedNotification::class,
    'rejected_notification' => App\Notifications\CustomRejectedNotification::class,
],
```

Your custom notification should accept a `MakerCheckerRequest` in its constructor:

```php
use Illuminate\Notifications\Notification;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class CustomPendingNotification extends Notification
{
    public function __construct(
        public MakerCheckerRequest $request,
        public ?string $role = null
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database', 'slack']; // Add any channels
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Custom: Approval Needed')
            ->line("Please review: {$this->request->description}")
            ->action('Review', url("/approvals/{$this->request->code}"));
    }

    // Add toSlack(), toArray(), etc. as needed
}
```

### Manual Notifications

Trigger notifications manually when needed:

```php
// Notify all approvers about a pending request
MakerChecker::notifyApprovers($request);

// Notify with sequential mode (only first role)
MakerChecker::notifyApprovers($request, sequential: true);

// Notify next approvers after partial approval
MakerChecker::notifyNextApprovers($request);

// Access the notification service directly
$service = MakerChecker::notifications();
$service->notifyPendingApproval($request);
$service->notifyRequestApproved($request);
$service->notifyRequestRejected($request);
```

## Lifecycle Callbacks

Register callbacks to execute at various points in the request lifecycle.

### Config-Based Callbacks

Define callbacks in the config file:

```php
// config/maker-checker.php
'callbacks' => [
    'on_initiated' => [
        App\MakerChecker\Callbacks\LogNewRequest::class,
        App\MakerChecker\Callbacks\SendSlackNotification::class,
    ],
    'after_approval' => [
        App\MakerChecker\Callbacks\UpdateAuditLog::class,
    ],
    'after_rejection' => [
        App\MakerChecker\Callbacks\NotifyManager::class,
    ],
    'on_failure' => [
        App\MakerChecker\Callbacks\AlertOps::class,
    ],
],
```

Callback classes should implement `RequestCallback` or have a `handle` method:

```php
use Moffhub\MakerChecker\Contracts\RequestCallback;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class LogNewRequest implements RequestCallback
{
    public function handle(MakerCheckerRequest $request): void
    {
        Log::info('New approval request', [
            'code' => $request->code,
            'type' => $request->type->value,
            'maker' => $request->maker_id,
        ]);
    }
}
```

### Programmatic Callbacks

Register callbacks at runtime:

```php
// In a service provider or bootstrap file
MakerChecker::callbacks()
    ->onInitiated(function (MakerCheckerRequest $request) {
        // Request was just created
        Log::info('Request initiated', ['code' => $request->code]);
    })
    ->afterApproval(function (MakerCheckerRequest $request) {
        // Request was fully approved and executed
        Notification::send($request->maker, new RequestCompleted($request));
    })
    ->afterRejection(function (MakerCheckerRequest $request) {
        // Request was rejected
        event(new RequestRejectedEvent($request));
    })
    ->onFailure(function (MakerCheckerRequest $request) {
        // Execution failed
        Alert::critical("Request {$request->code} failed");
    });
```

### Available Hooks

| Hook | When Executed |
|------|---------------|
| `on_initiated` | After a new request is created |
| `before_approval` | Before approval processing (per-request hooks only) |
| `after_approval` | After request is fully approved and executed |
| `before_rejection` | Before rejection processing (per-request hooks only) |
| `after_rejection` | After request is rejected |
| `on_failure` | When request execution fails |

## Rate Limiting

All package API routes are rate-limited by default. Configure the limit in your config:

```php
// config/maker-checker.php
'routes' => [
    'rate_limit' => env('MAKER_CHECKER_RATE_LIMIT', 60), // requests per minute
],
```

Rate limiting is keyed by the authenticated user's ID or by IP address for unauthenticated requests. Set to `0` or `null` to disable rate limiting.

The rate limiter is registered under the name `maker-checker`, so you can reference it in your own routes if needed:

```php
Route::middleware('throttle:maker-checker')->group(function () {
    // Your custom maker-checker routes
});
```

## Audit Logging

The package automatically logs all approval actions (approve, reject, cancel, fail) with full context.

### Configuration

```php
// config/maker-checker.php
'audit' => [
    'enabled' => env('MAKER_CHECKER_AUDIT_ENABLED', true),
    'driver' => env('MAKER_CHECKER_AUDIT_DRIVER', 'database'),
    'table_name' => 'maker_checker_audit_logs',
    'log_channel' => null, // Laravel log channel for 'log' driver
],
```

### Drivers

- **`database`** (default): Writes audit entries to the `maker_checker_audit_logs` table. Best for querying and reporting.
- **`log`**: Writes audit entries to a Laravel log channel. Best for high-throughput systems where you want to offload to external log aggregation (ELK, Datadog, etc.).

### Logged Data

Each audit entry includes:
- `request_id` - The maker-checker request ID
- `actor_type` / `actor_id` - Who performed the action (morph relationship)
- `action` - The action performed (approved, rejected, cancelled, partially_approved, failed)
- `previous_status` - The request status before the action
- `new_status` - The request status after the action
- `ip_address` - The IP address of the actor
- `metadata` - Additional context (e.g., exception messages for failures)

## Conditional Configuration

When using the database config driver, you can define conditions that determine which configuration applies based on the request payload. This allows different approval requirements for different scenarios.

### Supported Operators

| Operator | Description | Example Value |
|----------|-------------|---------------|
| `=` | Equal to | `50000` |
| `!=` | Not equal to | `"draft"` |
| `>` | Greater than | `10000` |
| `>=` | Greater than or equal | `5000` |
| `<` | Less than | `100` |
| `<=` | Less than or equal | `50` |
| `in` | Value in array | `["US", "EU", "UK"]` |
| `not_in` | Value not in array | `["blocked", "suspended"]` |
| `contains` | String contains | `"urgent"` |
| `starts_with` | String starts with | `"VIP-"` |
| `ends_with` | String ends with | `"@company.com"` |
| `is_null` | Value is null | _(no value needed)_ |
| `is_not_null` | Value is not null | _(no value needed)_ |
| `between` | Value between two numbers | `[1000, 50000]` |
| `regex` | Matches regex pattern | `"^[A-Z]{3}\\d{4}$"` |

### Condition Examples

**Simple condition - high-value transfers require extra approval:**

```json
{
    "mode": "all",
    "rules": [
        {"field": "amount", "operator": ">=", "value": 50000}
    ]
}
```

**Multiple conditions (AND) - large international transfers:**

```json
{
    "mode": "all",
    "rules": [
        {"field": "amount", "operator": ">=", "value": 10000},
        {"field": "currency", "operator": "!=", "value": "USD"},
        {"field": "destination_country", "operator": "not_in", "value": ["US", "CA"]}
    ]
}
```

**Any condition (OR) - sensitive operations:**

```json
{
    "mode": "any",
    "rules": [
        {"field": "amount", "operator": ">=", "value": 100000},
        {"field": "category", "operator": "=", "value": "executive"},
        {"field": "department", "operator": "in", "value": ["finance", "legal"]}
    ]
}
```

**Nested groups - complex business rules:**

```json
{
    "mode": "all",
    "rules": [
        {"field": "status", "operator": "=", "value": "active"}
    ],
    "groups": [
        {
            "mode": "any",
            "rules": [
                {"field": "amount", "operator": ">=", "value": 50000},
                {"field": "priority", "operator": "=", "value": "urgent"}
            ]
        }
    ]
}
```

**Using between for range checks:**

```json
{
    "mode": "all",
    "rules": [
        {"field": "amount", "operator": "between", "value": [10000, 49999]},
        {"field": "region", "operator": "in", "value": ["EMEA", "APAC"]}
    ]
}
```

### Testing Conditions

Use the test endpoint to verify which config matches a payload:

```http
POST /api/maker-checker/configs/test-conditions
Content-Type: application/json

{
    "configurable_type": "App\\Models\\Transfer",
    "action": "create",
    "payload": {
        "amount": 75000,
        "currency": "EUR",
        "destination_country": "DE"
    }
}
```

## Team Scoping

The package supports multi-tenant setups where requests and configurations are scoped to teams/companies.

### Setup

1. **Implement the User Contract** with team support:

```php
class User extends Authenticatable implements MakerCheckerUserContract
{
    public function getMakerCheckerTeamId(): ?int
    {
        return $this->team_id;
    }

    // ... other contract methods
}
```

2. **Pass team ID when creating requests:**

```php
MakerChecker::request()
    ->toCreate(Invoice::class, $data, teamId: auth()->user()->team_id)
    ->madeBy(auth()->user())
    ->save();
```

Or use the builder methods:

```php
MakerChecker::request()
    ->toCreate(Invoice::class, $data, requiredApprovals: [], teamId: $teamId)
    ->madeBy(auth()->user())
    ->save();
```

3. **Enable team scoping for notifications:**

```php
// config/maker-checker.php
'notifications' => [
    'team_scoping' => true,
    'team_attribute' => 'team_id', // attribute on user model
],
```

4. **Create team-scoped configs** (database driver):

```php
MakerCheckerConfig::create([
    'configurable_type' => Invoice::class,
    'action' => 'create',
    'approvals' => ['manager' => 1],
    'team_id' => 42, // Applies only to team 42
    'is_active' => true,
]);
```

### Visibility

Requests are automatically filtered by team when using `scopeVisibleTo`:

```php
// Users only see requests from their team
$requests = MakerCheckerRequest::visibleTo(auth()->user())->get();
```

Users with the `view_any_permission` bypass team filtering and see all requests.

## Custom ApproverResolver

The default `ApproverResolver` finds approvers by querying a `role` attribute on the user model. For more complex scenarios, implement your own resolver.

### Example: Spatie Permissions Integration

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class SpatieApproverResolver implements ApproverResolver
{
    public function getApproversForRole(MakerCheckerRequest $request, string $role): Collection
    {
        return User::permission("maker-checker.approve.{$role}")
            ->when($request->team_id, fn($q) => $q->where('team_id', $request->team_id))
            ->where('id', '!=', $request->maker_id)
            ->get();
    }

    public function getAllApprovers(MakerCheckerRequest $request): Collection
    {
        $requiredApprovals = $request->required_approvals ?? [];
        $roles = $requiredApprovals['roles'] ?? $requiredApprovals;
        $users = $requiredApprovals['users'] ?? [];

        $approvers = collect();

        foreach (array_keys($roles) as $role) {
            $approvers = $approvers->merge($this->getApproversForRole($request, $role));
        }

        if (!empty($users)) {
            $approvers = $approvers->merge($this->getApproversByIdentifier($request, $users));
        }

        return $approvers->unique('id');
    }

    public function getApproversByIdentifier(MakerCheckerRequest $request, array $userIdentifiers): Collection
    {
        return User::where(function ($query) use ($userIdentifiers) {
            $query->whereIn('email', $userIdentifiers)
                ->orWhereIn('id', $userIdentifiers);
        })->get();
    }

    public function getApproverByIdentifier(string $identifier): ?Model
    {
        return User::where('email', $identifier)
            ->orWhere('id', $identifier)
            ->first();
    }

    public function userExists(string $identifier): bool
    {
        return $this->getApproverByIdentifier($identifier) !== null;
    }

    public function validateUsersExist(array $userIdentifiers): array
    {
        return array_filter($userIdentifiers, fn($id) => !$this->userExists($id));
    }
}
```

Register it in your `AppServiceProvider`:

```php
$this->app->bind(ApproverResolver::class, SpatieApproverResolver::class);
```

## Custom ExecutableRequest

Create custom executable actions for complex operations that need approval:

```php
use Moffhub\MakerChecker\Contracts\ExecutableRequest;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class BulkUserImport extends ExecutableRequest
{
    /**
     * Execute the approved action.
     */
    public function execute(MakerCheckerRequest $request): void
    {
        $payload = $request->payload;

        foreach ($payload['users'] as $userData) {
            User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'role' => $userData['role'] ?? 'user',
                'team_id' => $request->team_id,
            ]);
        }
    }

    /**
     * Fields used to determine request uniqueness.
     * Prevents duplicate import requests with the same file hash.
     */
    public function uniqueBy(): array
    {
        return ['file_hash'];
    }

    /**
     * Runs before the request is approved.
     * Use for pre-flight validation.
     */
    public function beforeApproval(MakerCheckerRequest $request): void
    {
        $payload = $request->payload;

        // Verify no duplicate emails in the import
        $emails = array_column($payload['users'], 'email');
        $existing = User::whereIn('email', $emails)->pluck('email');

        if ($existing->isNotEmpty()) {
            throw new \RuntimeException(
                'Import contains existing emails: ' . $existing->implode(', ')
            );
        }
    }

    /**
     * Runs after successful approval and execution.
     */
    public function afterApproval(MakerCheckerRequest $request): void
    {
        $count = count($request->payload['users'] ?? []);
        Log::info("Bulk import completed: {$count} users imported", [
            'request_id' => $request->id,
            'team_id' => $request->team_id,
        ]);
    }

    /**
     * Runs before the request is rejected.
     */
    public function beforeRejection(MakerCheckerRequest $request): void
    {
        // Optional: cleanup temporary files
    }

    /**
     * Runs after the request is rejected.
     */
    public function afterRejection(MakerCheckerRequest $request): void
    {
        Notification::send($request->maker, new ImportRejectedNotification($request));
    }

    /**
     * Runs when execution fails.
     */
    public function onFailure(MakerCheckerRequest $request): void
    {
        Log::error('Bulk import failed', [
            'request_id' => $request->id,
            'exception' => $request->exception,
        ]);
    }
}
```

Use it:

```php
MakerChecker::request()
    ->toExecute(BulkUserImport::class, [
        'file_hash' => md5_file($uploadedFile->path()),
        'users' => $parsedUsers,
    ])
    ->withApprovals(['hr_manager' => 1, 'admin' => 1])
    ->madeBy(auth()->user())
    ->save();
```

## Testing

```bash
composer test
```

Run the full check suite:

```bash
composer check-code  # Runs lint, phpstan, and tests
```

## Configuration Reference

| Option | Default | Description |
|--------|---------|-------------|
| `ensure_requests_are_unique` | `true` | Prevent duplicate pending requests |
| `request_expiration_in_minutes` | `null` | Auto-expire after N minutes |
| `default_approval_count` | `1` | Default approvals when not specified |
| `table_name` | `maker_checker_requests` | Requests table name |
| `config_table_name` | `maker_checker_configs` | Configs table name |
| `delete_on_completion` | `true` | Delete requests after execution |
| `soft_delete_on_completion` | `false` | Soft delete instead |
| `view_any_permission` | `maker-checker.view-any` | Permission to view all requests |
| `config_driver` | `file` | `file` or `database` |
| `cache_config` | `true` | Cache database configs |
| `config_cache_ttl` | `3600` | Cache TTL in seconds |
| `routes.rate_limit` | `60` | Rate limit per minute (0 to disable) |
| `notifications.enabled` | `false` | Enable automatic notifications |
| `notifications.channels` | `['mail', 'database']` | Notification delivery channels |
| `notifications.notify_maker` | `true` | Notify maker on approval/rejection |
| `notifications.sequential` | `false` | Notify roles one at a time |
| `notifications.role_attribute` | `role` | User model attribute for role |
| `audit.enabled` | `true` | Enable audit logging |
| `audit.driver` | `database` | Audit storage: `database` or `log` |
| `audit.table_name` | `maker_checker_audit_logs` | Audit log table name |
| `audit.log_channel` | `null` | Laravel log channel for `log` driver |

## Troubleshooting

### "No authenticated user found" error

This error occurs when using the convenience methods (`MakerChecker::create()`, `MakerChecker::approve()`) without an authenticated user. Solutions:

- Ensure the user is authenticated before calling these methods
- Use the request builder with `->madeBy($user)` to explicitly pass a user
- For console commands or jobs, use the builder pattern instead of convenience methods

### "Request checker cannot be the same as the maker"

By default, the same user cannot both create and approve a request. To allow this for specific users (e.g., admins in development):

```php
// .env
MAKER_CHECKER_WHITELISTED_EMAILS=admin@example.com,super@example.com
```

### "The request model passed must be an instance of..."

This happens when:
- The `request_model` config points to a class that doesn't extend `MakerCheckerRequest`
- The config hasn't been published or is outdated

Fix: Ensure your custom model extends `MakerCheckerRequest`:

```php
class CustomRequest extends \Moffhub\MakerChecker\Models\MakerCheckerRequest
{
    // Your customizations
}
```

### Duplicate request exceptions

When `ensure_requests_are_unique` is `true`, creating a request with the same payload as an existing pending request throws a `DuplicateRequestException`. Solutions:

- Use `uniqueBy()` on the builder to specify which fields determine uniqueness
- Set `ensure_requests_are_unique` to `false` if duplicates are acceptable
- Approve or cancel existing pending requests first

### Notifications not sending

1. Ensure notifications are enabled: `'notifications.enabled' => true`
2. Verify `user_model` is set or `auth.providers.users.model` is configured
3. Check that your user model uses Laravel's `Notifiable` trait
4. Verify the `ApproverResolver` returns users for the required roles
5. Check your notification channels configuration

### Config validation errors on boot

The package validates configuration when the application boots (except during tests). Common issues:

- `default_approval_count` must be >= 1
- `config_driver` must be `file` or `database`
- `request_model` must be a class extending `MakerCheckerRequest`
- `whitelisted_models.maker` and `whitelisted_models.checker` must be arrays

### Database config not applying

When using the `database` config driver:
1. Ensure the config driver is set: `'config_driver' => 'database'`
2. Run migrations: `php artisan migrate`
3. Check configs are `is_active: true`
4. Clear config cache if changes aren't reflected: `php artisan cache:clear`
5. Verify the `team_id` matches (team-specific configs only apply to that team)

### Rate limiting too aggressive

Adjust the rate limit per minute:

```php
// config/maker-checker.php
'routes' => [
    'rate_limit' => 120, // Increase to 120 per minute
],
```

Or disable rate limiting entirely:

```php
'routes' => [
    'rate_limit' => 0, // Disabled
],
```

## Known Limitations

1. **No built-in queue support for fulfillment**: When a request is approved, the underlying operation (create/update/delete/execute) runs synchronously within the approval request. For long-running operations, implement your own queue dispatch inside an `ExecutableRequest`.

2. **JSON payload comparison**: Duplicate request checking uses JSON field comparisons (`payload->field`), which may behave differently across database engines (MySQL vs PostgreSQL vs SQLite).

3. **Single approval per user**: A user can only approve a request once. They cannot approve under multiple roles for the same request.

4. **No partial rollback**: If fulfillment fails after approval, the request is marked as `failed` but any partial side effects from hooks (`beforeApproval`) are not rolled back.

5. **Config driver is global**: You cannot use different config drivers for different models. The `config_driver` setting applies to all models.

6. **Morph map dependency**: The package uses polymorphic relationships for maker/checker/subject. If you change your morph map after requests are created, existing requests may break.

7. **No built-in approval deadlines**: While requests can expire, there is no built-in deadline per approval step in a multi-role chain. All roles have the same expiration window.

## Performance

For high-traffic production deployments, see [docs/PERFORMANCE.md](docs/PERFORMANCE.md) for:
- Recommended database indexes
- Query optimization tips
- Config caching recommendations
- Approval chain resolution at scale

## License

MIT License. See [LICENSE](LICENSE) for details.
