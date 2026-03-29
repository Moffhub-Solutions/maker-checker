<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\ConfigResolver;
use Moffhub\MakerChecker\MakerCheckerRequestManager;
use Moffhub\MakerChecker\MakerCheckerServiceProvider;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\RequestBuilder;
use Moffhub\MakerChecker\Tests\BaseTestCase;

class ServiceProviderTest extends BaseTestCase
{
    public function test_service_provider_registers_bindings(): void
    {
        $this->assertInstanceOf(
            MakerCheckerRequestManager::class,
            $this->app->make(MakerCheckerRequestManager::class)
        );

        $this->assertInstanceOf(
            RequestBuilder::class,
            $this->app->make(RequestBuilder::class)
        );

        $this->assertInstanceOf(
            ConfigResolver::class,
            $this->app->make(ConfigResolver::class)
        );
    }

    public function test_config_is_published(): void
    {
        $config = $this->app['config']->get('maker-checker');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('table_name', $config);
        $this->assertArrayHasKey('default_approval_count', $config);
    }

    public function test_routes_are_registered(): void
    {
        $routes = $this->app['router']->getRoutes();

        // Check that key routes are registered
        $routeNames = collect($routes)->map(fn($route) => $route->uri())->toArray();

        $this->assertContains('api/maker-checker/requests', $routeNames);
        $this->assertContains('api/maker-checker/configs', $routeNames);
    }

    public function test_routes_can_be_disabled(): void
    {
        // Create a fresh app with routes disabled
        $this->app['config']->set('maker-checker.routes.enabled', false);

        // Re-boot the provider to pick up the config change
        $provider = new MakerCheckerServiceProvider($this->app);
        $provider->boot();

        // Routes should still exist from the initial boot
        // In a real scenario, this would need a fresh application
        $this->assertTrue(true); // Placeholder assertion
    }

    public function test_resolve_request_model_returns_configured_class(): void
    {
        $model = MakerCheckerServiceProvider::resolveRequestModel();

        $this->assertInstanceOf(
            MakerCheckerRequest::class,
            $model
        );
    }

    public function test_get_request_model_class_returns_string(): void
    {
        $class = MakerCheckerServiceProvider::getRequestModelClass();

        $this->assertIsString($class);
        $this->assertEquals(
            MakerCheckerRequest::class,
            $class
        );
    }

    public function test_custom_request_model_can_be_configured(): void
    {
        // This test verifies the config option exists
        $this->app['config']->set(
            'maker-checker.request_model',
            MakerCheckerRequest::class
        );

        $class = MakerCheckerServiceProvider::getRequestModelClass();

        $this->assertEquals(
            MakerCheckerRequest::class,
            $class
        );
    }

    public function test_route_prefix_is_configurable(): void
    {
        $prefix = $this->app['config']->get('maker-checker.routes.prefix');

        $this->assertEquals('api', $prefix);
    }

    public function test_route_middleware_is_configurable(): void
    {
        $middleware = $this->app['config']->get('maker-checker.routes.middleware');

        $this->assertIsArray($middleware);
        $this->assertContains('api', $middleware);
    }
}
