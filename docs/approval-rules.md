# Approval Rules

This guide covers how to define who must approve a request before it is executed.

## Multi-Role Approvals (AND Logic)

By default, all specified roles must meet their approval thresholds. This is AND logic:

```php
// Requires 1 HR + 2 Admin + 1 Manager approvals (all of them)
MakerChecker::request()
    ->toCreate(User::class, $userData)
    ->withApprovals([
        'hr' => 1,
        'admin' => 2,
        'manager' => 1,
    ])
    ->madeBy(auth()->user())
    ->save();
```

Each role must independently reach its required count before the request is considered approved.

## User-Specific Approvals

Require specific people to approve, identified by email or ID:

```php
// Require the CFO to approve
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->requiringUsersToApprove(['cfo@company.com'])
    ->madeBy(auth()->user())
    ->save();

// Require multiple specific users
->requiringUsersToApprove(['cfo@company.com', 'ceo@company.com'])

// Require by user ID
->requiringUsersToApprove([(string) $cfoUser->id])
```

Only the specified users can approve the request. Other users will get an authorization error.

### Combining Roles and Users (AND)

Require both role-based and user-specific approvals:

```php
// Requires 1 admin approval AND the CFO to approve
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

By default, the package validates that all specified users exist in the system:

```php
// Throws RequestCouldNotBeInitiated if user doesn't exist
->requiringUsersToApprove(['nonexistent@company.com'])

// Disable validation if needed
->requiringUsersToApprove(['future@company.com'], validateExistence: false)
```

## OR Approval Mode

Use OR mode when a request should be approved if **any one** of several options is satisfied. This is useful for scenarios like "the CFO or any two directors can approve".

### Using `withAnyApproval()`

```php
// Approved when EITHER an admin OR a manager approves
MakerChecker::request()
    ->toCreate(Post::class, $data)
    ->withAnyApproval(['admin' => 1, 'manager' => 1])
    ->madeBy(auth()->user())
    ->save();
```

With AND mode (default), this would require both an admin and a manager. With OR mode, **either one** is sufficient.

### Using `withAnyRoleOrUserApproval()`

Mix roles and users with OR logic:

```php
// Approved when EITHER an admin approves OR the CFO approves
MakerChecker::request()
    ->toCreate(Contract::class, $data)
    ->withAnyRoleOrUserApproval(
        roles: ['admin' => 1],
        users: ['cfo@company.com']
    )
    ->madeBy(auth()->user())
    ->save();
```

### Using `withApprovalMode()`

Set the mode on top of existing approval configuration:

```php
MakerChecker::request()
    ->toCreate(Post::class, $data)
    ->withApprovals(['roles' => ['admin' => 1, 'manager' => 1]])
    ->withApprovalMode('any')
    ->madeBy(auth()->user())
    ->save();
```

### Role Thresholds in OR Mode

Each role still has its own required count. In OR mode, the request is approved as soon as **any single role** reaches its threshold:

```php
// Approved when EITHER 2 admins approve OR 1 manager approves
MakerChecker::request()
    ->toCreate(Post::class, $data)
    ->withAnyApproval(['admin' => 2, 'manager' => 1])
    ->madeBy(auth()->user())
    ->save();
```

If one admin approves, the threshold for `admin` (2) is not yet met, so the request stays `partially_approved`. But if a manager then approves, the `manager` threshold (1) is met, satisfying the OR condition.

### OR Mode with the Data Structure

Under the hood, OR mode adds a `mode` key to the `required_approvals` JSON:

```php
// AND mode (default)
['roles' => ['admin' => 1, 'manager' => 1], 'mode' => 'all']

// OR mode
['roles' => ['admin' => 1, 'manager' => 1], 'mode' => 'any']

// OR mode with roles and users
['roles' => ['admin' => 1], 'users' => ['cfo@example.com'], 'mode' => 'any']
```

This means you can also set OR mode in database-driven configs:

```php
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

## Checking Approval Status

```php
$request->getApprovalMode();            // 'all' or 'any'
$request->hasMetApprovalThreshold();    // true/false
$request->getApprovalCount();           // Total approvals received
$request->getPendingRoles();            // ['admin' => 1, ...] remaining
$request->getPendingUsers();            // ['cfo@company.com', ...] remaining
$request->requiresUserApprovals();      // true if users are required
$request->getApprovers();               // All approval records
```

> In OR mode, `getPendingRoles()` and `getPendingUsers()` return roles/users that haven't been fulfilled yet. Once any single item is fulfilled, the request is approved -- the remaining pending items are informational only.

## Summary: AND vs OR

| Scenario | Mode | Builder Method |
|----------|------|----------------|
| Admin **and** manager must approve | `all` (default) | `withApprovals([...])` |
| Admin **and** CFO (by email) must approve | `all` (default) | `withRoleAndUserApprovals(...)` |
| Admin **or** manager can approve | `any` | `withAnyApproval([...])` |
| Admin **or** CFO (by email) can approve | `any` | `withAnyRoleOrUserApproval(...)` |
| Set mode on existing config | either | `withApprovalMode('any')` |
