# API Reference

The package provides RESTful API endpoints for managing requests and configurations.

## Route Configuration

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

## Request Endpoints

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

Filter and sort requests:

```
GET /api/maker-checker/requests?status=pending&type=create&team_id=1
```

### Approval Response

The approval status endpoint returns the current state of approvals:

```json
{
    "data": {
        "approval_count": 1,
        "approval_mode": "any",
        "has_met_threshold": true,
        "pending_roles": {},
        "pending_users": [],
        "approvers": [
            {
                "checker_type": "App\\Models\\User",
                "checker_id": 5,
                "role": "admin",
                "user_email": "admin@example.com",
                "approved_at": "2024-01-01T12:00:00+00:00"
            }
        ]
    }
}
```

## Configuration Endpoints

These are available when `config_driver` is set to `'database'`.

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

### Creating a Config

```http
POST /api/maker-checker/configs
Content-Type: application/json

{
    "configurable_type": "App\\Models\\Contract",
    "action": "create",
    "approvals": {
        "roles": {"admin": 1, "legal": 1},
        "users": ["cfo@company.com", "ceo@company.com"],
        "mode": "any"
    },
    "conditions": {
        "mode": "all",
        "rules": [
            {"field": "amount", "operator": ">=", "value": 50000}
        ]
    },
    "priority": 100,
    "description": "High-value contract approval"
}
```

### Config Response

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
            "users": ["cfo@company.com", "ceo@company.com"],
            "mode": "any"
        },
        "role_approvals": {"admin": 1, "legal": 1},
        "user_approvals": ["cfo@company.com", "ceo@company.com"],
        "requires_user_approvals": true,
        "is_active": true,
        "priority": 100
    }
}
```

### Updating a Config

```http
PUT /api/maker-checker/configs/1
Content-Type: application/json

{
    "approvals": {
        "roles": {"admin": 2},
        "users": ["cfo@company.com"],
        "mode": "all"
    }
}
```

## Visibility Scoping

Requests are scoped based on the authenticated user:

- Users with the `view_any_permission` see all requests
- Users with a `team_id` see requests for their team
- Other users see only their own requests

```php
// Programmatic equivalent
$requests = MakerCheckerRequest::visibleTo(auth()->user())->get();
```
