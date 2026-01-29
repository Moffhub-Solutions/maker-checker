# MakerChecker

A Laravel package for implementing the Maker-Checker (Four Eyes) approval workflow pattern. This pattern ensures that critical operations require approval from one or more reviewers before execution.

## Features

- **Multi-role approvals** - Require approvals from specific roles (e.g., 2 admins + 1 manager)
- **CRUD operations** - Built-in support for Create, Update, Delete, and custom Execute operations
- **Flexible configuration** - Configure via file, database, or model interfaces
- **Multi-tenancy support** - Team/company scoping for requests
- **API ready** - RESTful API endpoints for managing requests
- **Hooks & callbacks** - Execute custom logic before/after approval or rejection
- **Request expiration** - Auto-expire pending requests after a configurable time
- **Audit trail** - Track who made and approved each request

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

MakerCheckerConfig::create([
    'configurable_type' => Post::class,
    'action' => 'delete',
    'approvals' => ['admin' => 2],
    'unique_fields' => [],
    'is_enabled' => true,
]);
```

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
        // Get all users who can approve any role
        $roles = array_keys($request->required_approvals ?? []);
        return User::role($roles)->get();
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
| `notifications.enabled` | `false` | Enable automatic notifications |
| `notifications.channels` | `['mail', 'database']` | Notification delivery channels |
| `notifications.notify_maker` | `true` | Notify maker on approval/rejection |
| `notifications.sequential` | `false` | Notify roles one at a time |
| `notifications.role_attribute` | `role` | User model attribute for role |

## License

MIT License. See [LICENSE](LICENSE) for details.
