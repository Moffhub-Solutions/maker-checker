<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Moffhub\MakerChecker\MakerCheckerServiceProvider;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;
use Orchestra\Testbench\TestCase;

abstract class BaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            MakerCheckerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('maker-checker.table_name', 'maker_checker_requests');
        $app['config']->set('maker-checker.config_table_name', 'maker_checker_configs');
        $app['config']->set('maker-checker.default_approval_count', 1);
        $app['config']->set('maker-checker.ensure_requests_are_unique', false);
        $app['config']->set('maker-checker.delete_on_completion', false);
        $app['config']->set('maker-checker.notifications.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../src/Database/Migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');
    }
}
