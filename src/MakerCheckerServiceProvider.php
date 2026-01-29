<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker;

use Illuminate\Foundation\Application;
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
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->publishes([
            __DIR__.'/Config/maker-checker.php' => config_path('maker-checker.php'),
        ], 'maker-checker-config');

        $this->publishes([
            __DIR__.'/../Database/Migrations/create_maker_checker_requests_table.php.stub' => $this->getMigrationFilePath('create_maker_checker_requests_table'),
        ], 'maker-checker-migration');

        $this->publishes([
            __DIR__.'/../Database/Migrations/create_maker_checker_configs_table.php.stub' => $this->getMigrationFilePath('create_maker_checker_configs_table'),
        ], 'maker-checker-config-migration');

        // Publish all migrations at once
        $this->publishes([
            __DIR__.'/../Database/Migrations/create_maker_checker_requests_table.php.stub' => $this->getMigrationFilePath('create_maker_checker_requests_table'),
            __DIR__.'/../Database/Migrations/create_maker_checker_configs_table.php.stub' => $this->getMigrationFilePath('create_maker_checker_configs_table'),
        ], 'maker-checker-migrations');
    }

    private function getMigrationFilePath(string $name): string
    {
        $currentTimestamp = date('Y_m_d_His');

        return database_path('migrations')."/{$currentTimestamp}_{$name}.php";
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
