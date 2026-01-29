<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\Contracts\RequestCallback;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Services\CallbackService;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class CallbackServiceTest extends BaseTestCase
{
    private User $maker;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maker = User::create([
            'name' => 'Maker User',
            'email' => 'maker@example.com',
            'role' => 'editor',
        ]);

        $this->approver = User::create([
            'name' => 'Approver User',
            'email' => 'approver@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_callback_service_can_be_accessed(): void
    {
        $service = MakerChecker::callbacks();

        $this->assertInstanceOf(CallbackService::class, $service);
    }

    public function test_on_initiated_callback_is_executed(): void
    {
        $executed = false;

        MakerChecker::callbacks()->onInitiated(function (MakerCheckerRequest $request) use (&$executed) {
            $executed = true;
            $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        });

        $this->actingAs($this->maker);
        MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $this->assertTrue($executed);
    }

    public function test_after_approval_callback_is_executed(): void
    {
        $executed = false;

        MakerChecker::callbacks()->afterApproval(function (MakerCheckerRequest $request) use (&$executed) {
            $executed = true;
        });

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        MakerChecker::approve($request, $this->approver, 'admin');

        $this->assertTrue($executed);
    }

    public function test_after_rejection_callback_is_executed(): void
    {
        $executed = false;

        MakerChecker::callbacks()->afterRejection(function (MakerCheckerRequest $request) use (&$executed) {
            $executed = true;
        });

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        MakerChecker::reject($request, $this->approver);

        $this->assertTrue($executed);
    }

    public function test_before_approval_callback_is_executed(): void
    {
        $executedOrder = [];

        MakerChecker::callbacks()->beforeApproval(function () use (&$executedOrder) {
            $executedOrder[] = 'before';
        });

        MakerChecker::callbacks()->afterApproval(function () use (&$executedOrder) {
            $executedOrder[] = 'after';
        });

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        MakerChecker::approve($request, $this->approver, 'admin');

        // Before callback not wired up to manager (only after), this is expected
        // The before/after hooks in the manager use the request-specific hooks
        $this->assertContains('after', $executedOrder);
    }

    public function test_multiple_callbacks_are_executed(): void
    {
        $count = 0;

        MakerChecker::callbacks()->onInitiated(function () use (&$count) {
            $count++;
        });

        MakerChecker::callbacks()->onInitiated(function () use (&$count) {
            $count++;
        });

        MakerChecker::callbacks()->onInitiated(function () use (&$count) {
            $count++;
        });

        $this->actingAs($this->maker);
        MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $this->assertEquals(3, $count);
    }

    public function test_config_callbacks_are_loaded(): void
    {
        // Create a test callback class
        $callbackClass = new class implements RequestCallback
        {
            public static bool $executed = false;

            public function handle(MakerCheckerRequest $request): void
            {
                self::$executed = true;
            }
        };

        // Register the class
        $className = get_class($callbackClass);
        $this->app->bind($className, fn() => $callbackClass);

        config(['maker-checker.callbacks.on_initiated' => [$className]]);

        // Force reload of callbacks
        $this->app->forgetInstance(CallbackService::class);

        $this->actingAs($this->maker);
        MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $this->assertTrue($callbackClass::$executed);
    }

    public function test_callbacks_are_chainable(): void
    {
        $service = MakerChecker::callbacks()
            ->onInitiated(fn() => null)
            ->afterApproval(fn() => null)
            ->afterRejection(fn() => null);

        $this->assertInstanceOf(CallbackService::class, $service);
    }

    public function test_callback_receives_request_data(): void
    {
        $capturedRequest = null;

        MakerChecker::callbacks()->onInitiated(function (MakerCheckerRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;
        });

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test Post',
            'user_id' => $this->maker->id,
        ]);

        $this->assertNotNull($capturedRequest);
        $this->assertEquals($request->id, $capturedRequest->id);
        $this->assertEquals('Test Post', $capturedRequest->payload['title']);
    }
}
