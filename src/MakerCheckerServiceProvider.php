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
        return MakerCheckerRequest::class;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireOverduePendingRequests::class]);
        }
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->publishes([
            __DIR__.'/Config/maker-checker.php' => config_path('maker-checker.php'),
        ], 'maker-checker-config');
        $this->publishes([
            __DIR__.'/Database/Migrations/create_maker_checker_requests_table.php.stub' => $this->getMigrationFilePath(),
        ], 'maker-checker-migration');
    }

    private function getMigrationFilePath(): string
    {
        $currentTimestamp = date('Y_m_d_His');

        return database_path('migrations')."/{$currentTimestamp}_create_maker_checker_requests_table.php";
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/maker-checker.php', 'maker-checker');
        $this->app->bind(MakerCheckerRequestManager::class,
            fn(Application $app) => new MakerCheckerRequestManager($app));
        $this->app->bind(RequestBuilder::class, fn(Application $app) => new RequestBuilder($app));
    }
}
