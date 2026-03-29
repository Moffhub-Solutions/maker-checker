# Getting Started

## Installation

Install via Composer:

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

## Setting Up Your User Model

Add the `MakerCheckerUserContract` interface to your User model so the package knows how to resolve roles, emails, and teams:

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

> The contract is optional for basic usage. If your User model has `email`, `role`, and `team_id` attributes, the package will discover them automatically. The contract gives you explicit control.

## Quick Start

There are three ways to use the package, from simplest to most flexible.

### Option A: Automatic Model Interception

Add the `RequiresApproval` trait to intercept create/update/delete operations automatically:

```php
use Moffhub\MakerChecker\Traits\RequiresApproval;

class Post extends Model
{
    use RequiresApproval;

    protected static array $requiresApprovalFor = ['create', 'delete'];

    protected static array $approvalRequirements = [
        'create' => ['editor' => 1],
        'delete' => ['admin' => 2],
    ];
}
```

Operations return `false` when intercepted, and a pending request is created:

```php
$post = new Post(['title' => 'My Post', 'user_id' => auth()->id()]);
$saved = $post->save();

if (!$saved && Post::wasIntercepted()) {
    $request = Post::getInterceptedRequest();
    return response()->json([
        'message' => 'Submitted for approval.',
        'request_code' => $request->code,
    ], 202);
}
```

See [Model Interception](model-interception.md) for full details.

### Option B: Convenience Methods

Use the `MakerChecker` facade with auto-injected authenticated user:

```php
use Moffhub\MakerChecker\Facades\MakerChecker;

$request = MakerChecker::create(Post::class, ['title' => 'My Post']);
$request = MakerChecker::update($post, ['title' => 'Updated Title']);
$request = MakerChecker::delete($post);
$request = MakerChecker::execute(TransferFunds::class, ['amount' => 5000]);
```

### Option C: Request Builder

For full control over approvals, hooks, and configuration:

```php
$request = MakerChecker::request()
    ->toCreate(Post::class, ['title' => 'My Post'])
    ->madeBy(auth()->user())
    ->description('Create a new blog post')
    ->withApprovals(['editor' => 1, 'admin' => 1])
    ->beforeApproval(fn($r) => Log::info('Approving...'))
    ->afterApproval(fn($r) => Notification::send(...))
    ->save();
```

## Approving, Rejecting, and Cancelling

```php
// Via facade (auto-injects auth user)
MakerChecker::approve($request);
MakerChecker::approve($request, null, 'admin', 'Looks good!');
MakerChecker::reject($request, null, 'Missing information');
MakerChecker::cancel($request);

// Or directly on the request model
$request->approve();
$request->approve(null, 'admin');
$request->reject(null, 'Not approved');
$request->cancel(); // Only the maker can cancel
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

## Next Steps

- [Request Types](request-types.md) - Create, Update, Delete, Execute operations
- [Approval Rules](approval-rules.md) - Multi-role, user-specific, and OR approval modes
- [Configuration](configuration.md) - File, model, and database configuration
- [Model Interception](model-interception.md) - Automatic Eloquent event interception
