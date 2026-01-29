<?php

declare(strict_types=1);

use Moffhub\MakerChecker\Models\MakerCheckerRequest;

return [
    /*
    |--------------------------------------------------------------------------
    | Request Uniqueness
    |--------------------------------------------------------------------------
    |
    | This configuration determines whether to check for duplicate pending
    | requests before creating a new one. If set to false, duplicate requests
    | (with similar payload/subjects) will be allowed.
    |
    */
    'ensure_requests_are_unique' => true,

    /*
    |--------------------------------------------------------------------------
    | Request Expiration
    |--------------------------------------------------------------------------
    |
    | The time, in minutes, after which a pending request is marked as expired.
    | If left as null, pending requests will remain until acted upon.
    |
    */
    'request_expiration_in_minutes' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Approval Count
    |--------------------------------------------------------------------------
    |
    | The default number of approvals required when no specific requirements
    | are set on the model or request.
    |
    */
    'default_approval_count' => 1,

    /*
    |--------------------------------------------------------------------------
    | Whitelisted Models
    |--------------------------------------------------------------------------
    |
    | Limit which models can initiate or approve requests. If left empty,
    | any model can perform these actions.
    |
    */
    'whitelisted_models' => [
        'maker' => [], // e.g., [App\Models\User::class]
        'checker' => [], // e.g., [App\Models\Admin::class]
    ],

    /*
    |--------------------------------------------------------------------------
    | Whitelisted Emails
    |--------------------------------------------------------------------------
    |
    | Users with these emails are allowed to be both the maker and checker
    | of the same request. Useful for development/testing or special roles.
    | Comma-separated list.
    |
    */
    'whitelisted_emails' => env('MAKER_CHECKER_WHITELISTED_EMAILS', ''),

    /*
    |--------------------------------------------------------------------------
    | Request Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used for storing maker-checker requests.
    | You can extend the default model to add custom functionality.
    |
    */
    'request_model' => MakerCheckerRequest::class,

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    |
    | The database table name for storing requests.
    |
    */
    'table_name' => 'maker_checker_requests',

    /*
    |--------------------------------------------------------------------------
    | Soft Delete on Completion
    |--------------------------------------------------------------------------
    |
    | If true, completed requests will be soft-deleted instead of hard-deleted.
    | This requires adding SoftDeletes trait to your request model.
    |
    */
    'soft_delete_on_completion' => false,

    /*
    |--------------------------------------------------------------------------
    | Delete on Completion
    |--------------------------------------------------------------------------
    |
    | If true, requests will be deleted after successful execution.
    | Set to false to keep all requests for audit purposes.
    |
    */
    'delete_on_completion' => true,

    /*
    |--------------------------------------------------------------------------
    | Permission for View Any
    |--------------------------------------------------------------------------
    |
    | The permission name/key that allows a user to view all requests.
    | This is used in the scopeVisibleTo query scope.
    | Set to null to disable permission-based visibility.
    |
    */
    'view_any_permission' => 'maker-checker.view-any',

    /*
    |--------------------------------------------------------------------------
    | Global Approval Requirements
    |--------------------------------------------------------------------------
    |
    | Default approval requirements for all models. These are used when a model
    | doesn't implement MakerCheckerConfigurable or doesn't specify requirements.
    |
    | Format: ['role_name' => count] or just an integer for any-role approvals
    |
    | Example:
    | 'global_approvals' => [
    |     'create' => ['admin' => 1],
    |     'update' => ['admin' => 1],
    |     'delete' => ['admin' => 2, 'super_admin' => 1],
    |     'execute' => 1,
    | ],
    |
    */
    'global_approvals' => [
        'create' => [],
        'update' => [],
        'delete' => [],
        'execute' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Define approval requirements per model without implementing the interface.
    | This is useful when you can't modify the model class.
    |
    | Example:
    | 'models' => [
    |     App\Models\User::class => [
    |         'approvals' => [
    |             'create' => ['hr' => 1, 'admin' => 1],
    |             'update' => ['admin' => 1],
    |             'delete' => ['admin' => 2],
    |         ],
    |         'unique_fields' => [
    |             'create' => ['email'],
    |         ],
    |         'required_for' => ['create', 'delete'], // empty = all actions
    |     ],
    | ],
    |
    */
    'models' => [],

    /*
    |--------------------------------------------------------------------------
    | Executable-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Define approval requirements for ExecutableRequest classes.
    |
    | Example:
    | 'executables' => [
    |     App\MakerChecker\TransferFunds::class => [
    |         'approvals' => ['finance' => 1, 'manager' => 1],
    |         'unique_fields' => ['account_from', 'account_to', 'amount'],
    |     ],
    | ],
    |
    */
    'executables' => [],

    /*
    |--------------------------------------------------------------------------
    | User Resource Class
    |--------------------------------------------------------------------------
    |
    | The resource class used for serializing user/maker/checker in API responses.
    | Set to null to use the built-in flexible UserResource that auto-detects
    | name attributes. Provide your own class to fully customize the output.
    |
    | Your custom class should extend Moffhub\MakerChecker\Http\Resources\Resource
    | and define the formatting methods you need.
    |
    */
    'user_resource' => null,

    /*
    |--------------------------------------------------------------------------
    | Configuration Driver
    |--------------------------------------------------------------------------
    |
    | Choose where to load model/action configuration from:
    | - 'file': Use this config file (default, zero setup)
    | - 'database': Use maker_checker_configs table (API-driven, dynamic)
    |
    | With 'database' driver, you can manage configs via API and build custom
    | admin UIs. The ConfigRepository provides CRUD operations for this.
    |
    | Priority order (highest to lowest):
    | 1. Explicit parameters passed to RequestBuilder
    | 2. Model implementing MakerCheckerConfigurable interface
    | 3. Database config (if driver is 'database')
    | 4. Config file model-specific settings
    | 5. Config file executable-specific settings
    | 6. Config file global_approvals
    | 7. default_approval_count
    |
    */
    'config_driver' => env('MAKER_CHECKER_CONFIG_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Config Table Name
    |--------------------------------------------------------------------------
    |
    | The database table name for storing configurations when using the
    | 'database' driver.
    |
    */
    'config_table_name' => 'maker_checker_configs',

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Enable caching of database configurations for better performance.
    | Cache is automatically cleared when configs are modified.
    |
    */
    'cache_config' => env('MAKER_CHECKER_CACHE_CONFIG', true),

    /*
    |--------------------------------------------------------------------------
    | Config Cache TTL
    |--------------------------------------------------------------------------
    |
    | Time-to-live in seconds for cached configurations.
    | Default: 3600 (1 hour)
    |
    */
    'config_cache_ttl' => env('MAKER_CHECKER_CONFIG_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | API Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the API routes for maker-checker.
    |
    | - enabled: Set to false to disable automatic route registration.
    |            You can then manually include routes or customize them.
    | - prefix: URL prefix for all routes (default: 'api')
    | - middleware: Array of middleware to apply to all routes
    |
    | Full routes will be: {prefix}/maker-checker/requests, etc.
    |
    | To customize routes, publish them:
    | php artisan vendor:publish --tag=maker-checker-routes
    |
    */
    'routes' => [
        'enabled' => env('MAKER_CHECKER_ROUTES_ENABLED', true),
        'prefix' => env('MAKER_CHECKER_ROUTES_PREFIX', 'api'),
        'middleware' => ['api'],
    ],
];
