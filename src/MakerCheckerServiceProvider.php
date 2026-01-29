<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Moffhub\MakerChecker\Console\Commands\ExpireOverDuePendingRequests;
use Moffhub\MakerChecker\Exceptions\InvalidRequestModelSet;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

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

        // Publish all migrations at once (only those that don't exist)
        $migrations = [];
        if (!$this->migrationExists('create_maker_checker_requests_table')) {
            $migrations[__DIR__.'/Database/Migrations/create_maker_checker_requests_table.php.stub'] = $this->getMigrationFilePath('create_maker_checker_requests_table');
        }
        if (!$this->migrationExists('create_maker_checker_configs_table')) {
            $migrations[__DIR__.'/Database/Migrations/create_maker_checker_configs_table.php.stub'] = $this->getMigrationFilePath('create_maker_checker_configs_table');
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
    }
}
