# Configuration

Approval requirements can be defined in multiple ways. The package checks them in priority order and uses the first match.

**Resolution priority (highest to lowest):**

1. Explicit parameters passed to `RequestBuilder` (`withApprovals()`, etc.)
2. Model implementing `MakerCheckerConfigurable` interface
3. Database config (if `config_driver` is `'database'`)
4. Config file model-specific settings
5. Config file executable-specific settings (for execute type)
6. Config file `global_approvals`
7. `default_approval_count` (defaults to 1)

## File-Based Configuration

Configure approval rules in `config/maker-checker.php`:

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
        'required_for' => ['create', 'delete'], // Only these actions need approval
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

## Model-Based Configuration

Implement `MakerCheckerConfigurable` on your model for programmatic control:

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

## Database-Driven Configuration

Enable the database driver for dynamic, runtime-configurable approval rules:

```php
// config/maker-checker.php
'config_driver' => 'database',
```

### Creating Configs Programmatically

```php
use Moffhub\MakerChecker\Models\MakerCheckerConfig;

// Role-based approvals
MakerCheckerConfig::create([
    'configurable_type' => Post::class,
    'action' => 'delete',
    'approvals' => ['roles' => ['admin' => 2]],
    'is_active' => true,
]);

// Role + user approvals
MakerCheckerConfig::create([
    'configurable_type' => Contract::class,
    'action' => 'create',
    'approvals' => [
        'roles' => ['admin' => 1, 'legal' => 1],
        'users' => ['cfo@company.com'],
    ],
    'description' => 'High-value contracts require CFO approval',
    'is_active' => true,
]);

// OR mode approvals
MakerCheckerConfig::create([
    'configurable_type' => Contract::class,
    'action' => 'create',
    'approvals' => [
        'roles' => ['cfo' => 1, 'finance_director' => 1],
        'mode' => 'any',
    ],
    'is_active' => true,
]);
```

### Priority and Conditions

Database configs support priority ordering and conditional rules. See [Conditional Approvals](conditional-approvals.md) for details on payload-based conditions.

```php
// Higher priority configs are evaluated first
MakerCheckerConfig::create([
    'configurable_type' => Comment::class,
    'action' => 'create',
    'priority' => 100,                    // Checked first
    'conditions' => [
        'mode' => 'all',
        'rules' => [['field' => 'budget', 'operator' => '>=', 'value' => 100000]],
    ],
    'approvals' => ['roles' => ['ceo' => 1, 'finance' => 1]],
    'is_active' => true,
]);
```

### Configuration API

Create and manage configs via the REST API:

```http
POST /api/maker-checker/configs
Content-Type: application/json

{
    "configurable_type": "App\\Models\\Contract",
    "action": "create",
    "approvals": {
        "roles": {"admin": 1, "legal": 1},
        "users": ["cfo@company.com"]
    },
    "description": "Contract creation approval workflow"
}
```

See [API Reference](api-reference.md) for the full list of config endpoints.

### Caching

Database configs are cached by default:

```php
// config/maker-checker.php
'cache_config' => true,
'config_cache_ttl' => 3600, // seconds
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
