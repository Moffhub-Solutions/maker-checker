# MakerChecker

A Laravel package for implementing the Maker-Checker (Four Eyes) approval workflow pattern. Critical operations require approval from one or more reviewers before execution.

## Features

- **Multi-role approvals** - Require approvals from specific roles (e.g., 2 admins + 1 manager)
- **User-specific approvals** - Require named users to approve (by email or ID)
- **OR approval mode** - Allow approval by any one of several roles or users
- **CRUD operations** - Built-in support for Create, Update, Delete, and custom Execute operations
- **Flexible configuration** - Configure via file, database, or model interfaces
- **Conditional rules** - Apply different approval rules based on payload data
- **Multi-tenancy support** - Team/company scoping for requests
- **API ready** - RESTful API endpoints for managing requests and configs
- **Hooks & callbacks** - Execute custom logic before/after approval or rejection
- **Notifications** - Auto-notify approvers with customizable notification classes
- **Model interception** - Automatically intercept Eloquent create/update/delete events
- **Request expiration** - Auto-expire pending requests after a configurable time

## Installation

```bash
composer require moffhub/maker-checker

php artisan vendor:publish --tag=maker-checker-migrations
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=maker-checker-config
```

## Quick Start

### Create a Request

```php
use Moffhub\MakerChecker\Facades\MakerChecker;

// Simple — auto-injects authenticated user
$request = MakerChecker::create(Post::class, ['title' => 'My Post']);

// With builder — full control
$request = MakerChecker::request()
    ->toCreate(Post::class, ['title' => 'My Post'])
    ->withApprovals(['editor' => 1, 'admin' => 1])
    ->madeBy(auth()->user())
    ->save();
```

### Approve or Reject

```php
MakerChecker::approve($request, null, 'admin', 'Looks good!');
MakerChecker::reject($request, null, 'Missing information');

// Or directly on the request
$request->approve(null, 'admin');
$request->reject(null, 'Not approved');
```

### OR Approvals

Allow a request to be approved by **any one** of several roles or users:

```php
// Either an admin OR a manager can approve
MakerChecker::request()
    ->toCreate(Post::class, $data)
    ->withAnyApproval(['admin' => 1, 'manager' => 1])
    ->madeBy(auth()->user())
    ->save();

// Either an admin role OR the CFO can approve
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->withAnyRoleOrUserApproval(
        roles: ['admin' => 1],
        users: ['cfo@company.com']
    )
    ->madeBy(auth()->user())
    ->save();
```

### Automatic Model Interception

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

## Documentation

| Guide | Description |
|-------|-------------|
| [Getting Started](docs/getting-started.md) | Installation, user setup, quick start options |
| [Request Types](docs/request-types.md) | Create, Update, Delete, and Execute operations |
| [Approval Rules](docs/approval-rules.md) | Multi-role, user-specific, and OR approval modes |
| [Configuration](docs/configuration.md) | File, model, and database config drivers |
| [Conditional Approvals](docs/conditional-approvals.md) | Payload-based conditional rules |
| [Model Interception](docs/model-interception.md) | Automatic Eloquent event interception |
| [Hooks & Callbacks](docs/hooks-and-callbacks.md) | Lifecycle hooks and global callbacks |
| [Notifications](docs/notifications.md) | Auto-notifications, custom classes, sequential mode |
| [API Reference](docs/api-reference.md) | REST API endpoints and route configuration |

## Testing

```bash
composer test
composer check-code  # Runs lint, phpstan, and tests
```

## License

MIT License. See [LICENSE](LICENSE) for details.
