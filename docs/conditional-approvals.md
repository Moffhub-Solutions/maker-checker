# Conditional Approvals

Conditional approvals let you apply different approval rules based on the request payload. This is only available with the database config driver.

For example: transactions under $50k need one finance approval, but transactions over $100k need the CEO.

## Setup

Enable the database driver:

```php
// config/maker-checker.php
'config_driver' => 'database',
```

## Basic Conditions

Create configs with conditions that match against the request payload:

```php
use Moffhub\MakerChecker\Models\MakerCheckerConfig;

// High-value: requires CEO + Finance
MakerCheckerConfig::create([
    'configurable_type' => Transaction::class,
    'action' => 'create',
    'priority' => 100,  // Higher priority = checked first
    'conditions' => [
        'mode' => 'all',
        'rules' => [
            ['field' => 'amount', 'operator' => '>=', 'value' => 100000],
        ],
    ],
    'approvals' => ['roles' => ['ceo' => 1, 'finance' => 1]],
    'is_active' => true,
]);

// Default: requires Finance only
MakerCheckerConfig::create([
    'configurable_type' => Transaction::class,
    'action' => 'create',
    'priority' => 0,     // Fallback
    'conditions' => null, // No conditions = matches everything
    'approvals' => ['roles' => ['finance' => 1]],
    'is_active' => true,
]);
```

When a request is created with `amount >= 100000`, the first config matches. Otherwise, the fallback applies.

## Condition Modes

### AND Mode (`mode: 'all'`)

All rules must match:

```php
'conditions' => [
    'mode' => 'all',
    'rules' => [
        ['field' => 'amount', 'operator' => '>=', 'value' => 50000],
        ['field' => 'region', 'operator' => '=', 'value' => 'APAC'],
    ],
]
// Matches when: amount >= 50000 AND region = APAC
```

### OR Mode (`mode: 'any'`)

At least one rule must match:

```php
'conditions' => [
    'mode' => 'any',
    'rules' => [
        ['field' => 'amount', 'operator' => '>=', 'value' => 100000],
        ['field' => 'priority', 'operator' => '=', 'value' => 'urgent'],
    ],
]
// Matches when: amount >= 100000 OR priority = urgent
```

## Nested Condition Groups

Combine AND and OR logic with nested groups:

```php
'conditions' => [
    'mode' => 'all',
    'rules' => [
        ['field' => 'amount', 'operator' => '>=', 'value' => 50000],
    ],
    'groups' => [
        [
            'mode' => 'any',
            'rules' => [
                ['field' => 'region', 'operator' => '=', 'value' => 'APAC'],
                ['field' => 'region', 'operator' => '=', 'value' => 'EMEA'],
            ],
        ],
    ],
]
// Matches when: amount >= 50000 AND (region = APAC OR region = EMEA)
```

## Supported Operators

| Operator | Description | Example Value |
|----------|-------------|---------------|
| `=` | Equals | `100` |
| `!=` | Not equals | `"draft"` |
| `>` | Greater than | `50000` |
| `>=` | Greater than or equal | `50000` |
| `<` | Less than | `1000` |
| `<=` | Less than or equal | `1000` |
| `in` | Value in array | `["US", "EU", "UK"]` |
| `not_in` | Value not in array | `["blocked", "suspended"]` |
| `contains` | String contains | `"urgent"` |
| `starts_with` | String starts with | `"VIP-"` |
| `ends_with` | String ends with | `"@company.com"` |
| `is_null` | Value is null | *(no value needed)* |
| `is_not_null` | Value is not null | *(no value needed)* |
| `between` | Value in range | `[1000, 50000]` |
| `regex` | Matches regex | `"^[A-Z]{3}-\\d+"` |

## Dot Notation

Access nested payload fields with dot notation:

```php
'rules' => [
    ['field' => 'sender.country', 'operator' => 'in', 'value' => ['US', 'UK']],
    ['field' => 'metadata.risk_score', 'operator' => '>=', 'value' => 80],
]
```

## Priority

When multiple configs match, the one with the highest `priority` value wins:

```php
// Priority 100: high-value rule (checked first)
MakerCheckerConfig::create([
    'priority' => 100,
    'conditions' => ['mode' => 'all', 'rules' => [['field' => 'amount', 'operator' => '>=', 'value' => 100000]]],
    'approvals' => ['roles' => ['ceo' => 1, 'finance' => 1]],
    // ...
]);

// Priority 50: medium-value rule
MakerCheckerConfig::create([
    'priority' => 50,
    'conditions' => ['mode' => 'all', 'rules' => [['field' => 'amount', 'operator' => '>=', 'value' => 10000]]],
    'approvals' => ['roles' => ['manager' => 1, 'finance' => 1]],
    // ...
]);

// Priority 0: default fallback (no conditions)
MakerCheckerConfig::create([
    'priority' => 0,
    'conditions' => null,
    'approvals' => ['roles' => ['finance' => 1]],
    // ...
]);
```

## Team Scoping

Conditions can be scoped to specific teams for multi-tenant setups:

```php
MakerCheckerConfig::create([
    'configurable_type' => Transaction::class,
    'action' => 'create',
    'team_id' => 1,  // Only applies to team 1
    'conditions' => [/* ... */],
    'approvals' => [/* ... */],
    'is_active' => true,
]);
```

## Combining Conditions with OR Approvals

Conditional rules determine *which* approval config applies. The `mode` inside `approvals` determines *how* those approvals are evaluated:

```php
MakerCheckerConfig::create([
    'configurable_type' => Transaction::class,
    'action' => 'create',
    'conditions' => [
        'mode' => 'all',
        'rules' => [['field' => 'amount', 'operator' => '>=', 'value' => 100000]],
    ],
    'approvals' => [
        'roles' => ['ceo' => 1, 'board_member' => 1],
        'mode' => 'any',  // Either the CEO or a board member can approve
    ],
    'is_active' => true,
]);
```
