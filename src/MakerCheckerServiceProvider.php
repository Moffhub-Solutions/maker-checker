<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Moffhub\MakerChecker\Console\Commands\ExpireOverDuePendingRequests;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Exceptions\InvalidRequestModelSet;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Services\CallbackService;
use Moffhub\MakerChecker\Services\DefaultApproverResolver;
use Moffhub\MakerChecker\Services\NotificationService;

class MakerCheckerServiceProvider extends ServiceProvider
{
    /**
     * @throws InvalidRequestModelSet
     */
    public static function resolveRequestModel(): MakerCheckerRequest
    {
        $requestModel = self::getRequestModelClass();
        $instance = new $requestModel;
        if (!$instance instanceof MakerCheckerRequest) {
            throw InvalidRequestModelSet::create();
        }

        return $instance;
    }

    public static function getRequestModelClass(): string
    {
        return config('maker-checker.request_model', MakerCheckerRequest::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireOverDuePendingRequests::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->registerRoutes();

        $this->publishes([
            __DIR__.'/Config/maker-checker.php' => config_path('maker-checker.php'),
        ], 'maker-checker-config');

        // Only publish migrations if they don't already exist
        if (!$this->migrationExists('create_maker_checker_requests_table')) {
            $this->publishes([
                __DIR__.'/Database/Migrations/create_maker_checker_requests_table.php.stub' => $this->getMigrationFilePath('create_maker_checker_requests_table'),
            ], 'maker-checker-migration');
        }

        if (!$this->migrationExists('create_maker_checker_configs_table')) {
            $this->publishes([
                __DIR__.'/Database/Migrations/create_maker_checker_configs_table.php.stub' => $this->getMigrationFilePath('create_maker_checker_configs_table'),
            ], 'maker-checker-config-migration');
        }

        if (!$this->migrationExists('add_conditions_to_maker_checker_configs_table')) {
            $this->publishes([
                __DIR__.'/Database/Migrations/add_conditions_to_maker_checker_configs_table.php.stub' => $this->getMigrationFilePath('add_conditions_to_maker_checker_configs_table'),
            ], 'maker-checker-conditions-migration');
        }

        // Publish all migrations at once (only those that don't exist)
        $migrations = [];
        if (!$this->migrationExists('create_maker_checker_requests_table')) {
            $migrations[__DIR__.'/Database/Migrations/create_maker_checker_requests_table.php.stub'] = $this->getMigrationFilePath('create_maker_checker_requests_table');
        }
        if (!$this->migrationExists('create_maker_checker_configs_table')) {
            $migrations[__DIR__.'/Database/Migrations/create_maker_checker_configs_table.php.stub'] = $this->getMigrationFilePath('create_maker_checker_configs_table');
        }
        if (!$this->migrationExists('add_conditions_to_maker_checker_configs_table')) {
            $migrations[__DIR__.'/Database/Migrations/add_conditions_to_maker_checker_configs_table.php.stub'] = $this->getMigrationFilePath('add_conditions_to_maker_checker_configs_table');
        }
        if (!empty($migrations)) {
            $this->publishes($migrations, 'maker-checker-migrations');
        }

        // Publish routes for customization
        $this->publishes([
            __DIR__.'/Http/routes.php' => base_path('routes/maker-checker.php'),
        ], 'maker-checker-routes');
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        if (!config('maker-checker.routes.enabled', true)) {
            return;
        }

        Route::group($this->routeConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        });
    }

    /**
     * Get the route group configuration.
     *
     * @return array<string, mixed>
     */
    protected function routeConfiguration(): array
    {
        return [
            'prefix' => config('maker-checker.routes.prefix', 'api'),
            'middleware' => config('maker-checker.routes.middleware', ['api']),
        ];
    }

    private function getMigrationFilePath(string $name): string
    {
        $currentTimestamp = date('Y_m_d_His');

        return database_path('migrations')."/{$currentTimestamp}_{$name}.php";
    }

    /**
     * Check if a migration file already exists in the database/migrations folder.
     */
    private function migrationExists(string $migrationName): bool
    {
        $migrationsPath = database_path('migrations');

        if (!is_dir($migrationsPath)) {
            return false;
        }

        $files = glob($migrationsPath.'/*_'.$migrationName.'.php');

        return !empty($files);
    }

    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/maker-checker.php', 'maker-checker');
        $this->app->bind(MakerCheckerRequestManager::class,
            fn(Application $app): \Moffhub\MakerChecker\MakerCheckerRequestManager => new MakerCheckerRequestManager($app));
        $this->app->bind(RequestBuilder::class, fn(Application $app): \Moffhub\MakerChecker\RequestBuilder => new RequestBuilder($app));
        $this->app->singleton(ConfigResolver::class, fn(Application $app): \Moffhub\MakerChecker\ConfigResolver => new ConfigResolver($app['config']['maker-checker']));

        // Register the approver resolver (can be overridden by user)
        $this->app->bind(ApproverResolver::class, DefaultApproverResolver::class);

        // Register services
        $this->app->singleton(CallbackService::class, fn(Application $app): CallbackService => new CallbackService($app));
        $this->app->singleton(NotificationService::class, fn(Application $app): NotificationService => new NotificationService(
            $app->make(ApproverResolver::class)
        ));

        // Register event listeners for automatic notifications
        $this->registerEventListeners();
    }

    /**
     * Register event listeners for callbacks.
     *
     * Notifications are NOT sent automatically by the package.
     * Instead, listen to the dispatched events in your application and handle
     * notifications however you like. The NotificationService and notification
     * classes are available as optional helpers.
     *
     * Events dispatched:
     * - RequestInitiated — when a new request is created
     * - RequestApproved  — when a request is fully approved and fulfilled
     * - RequestRejected  — when a request is rejected
     * - RequestCancelled — when a request is cancelled by the maker
     * - RequestFailed    — when request fulfillment fails
     */
    protected function registerEventListeners(): void
    {
        // On request initiated - execute config callbacks
        $this->app['events']->listen(RequestInitiated::class, function (RequestInitiated $event) {
            $this->app->make(CallbackService::class)->executeOnInitiated($event->request);
        });

        // On request approved - execute config callbacks
        $this->app['events']->listen(RequestApproved::class, function (RequestApproved $event) {
            $this->app->make(CallbackService::class)->executeAfterApproval($event->request);
        });

        // On request rejected - execute config callbacks
        $this->app['events']->listen(RequestRejected::class, function (RequestRejected $event) {
            $this->app->make(CallbackService::class)->executeAfterRejection($event->request);
        });
    }
}
