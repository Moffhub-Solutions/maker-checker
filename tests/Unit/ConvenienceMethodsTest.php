<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

/**
 * Tests for convenience methods on MakerChecker facade and MakerCheckerRequest model.
 */
class ConvenienceMethodsTest extends BaseTestCase
{
    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();

        // Set default approval count to 1 for these tests
        config(['maker-checker.default_approval_count' => 1]);
        // Clear any global/model approvals to ensure default_approval_count is used
        config(['maker-checker.global_approvals' => []]);
        config(['maker-checker.models' => []]);

        $this->maker = User::create([
            'name' => 'Maker User',
            'email' => 'maker@example.com',
            'role' => 'editor',
        ]);

        $this->checker = User::create([
            'name' => 'Checker User',
            'email' => 'checker@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_facade_create_method(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'New Post',
            'content' => 'Post content',
            'user_id' => $this->maker->id,
        ]);

        $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        $this->assertEquals(RequestType::CREATE, $request->type);
        $this->assertEquals(RequestStatus::PENDING, $request->status);
        $this->assertEquals(Post::class, $request->subject_type);
        $this->assertEquals('New Post', $request->payload['title']);
        $this->assertEquals($this->maker->id, $request->maker_id);
    }

    public function test_facade_create_with_description(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(
            Post::class,
            ['title' => 'Test', 'user_id' => $this->maker->id],
            'Creating a test post'
        );

        $this->assertEquals('Creating a test post', $request->description);
    }

    public function test_facade_update_method(): void
    {
        $this->actingAs($this->maker);

        $post = Post::create([
            'title' => 'Original',
            'user_id' => $this->maker->id,
        ]);

        $request = MakerChecker::update($post, ['title' => 'Updated Title']);

        $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        $this->assertEquals(RequestType::UPDATE, $request->type);
        $this->assertEquals($post->id, $request->subject_id);
        $this->assertEquals(['title' => 'Updated Title'], $request->payload);
    }

    public function test_facade_delete_method(): void
    {
        $this->actingAs($this->maker);

        $post = Post::create([
            'title' => 'To Delete',
            'user_id' => $this->maker->id,
        ]);

        $request = MakerChecker::delete($post);

        $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        $this->assertEquals(RequestType::DELETE, $request->type);
        $this->assertEquals($post->id, $request->subject_id);
    }

    public function test_facade_create_throws_when_not_authenticated(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated user found');

        MakerChecker::create(Post::class, ['title' => 'Test']);
    }

    public function test_facade_approve_with_authenticated_user(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Switch to checker
        $this->actingAs($this->checker);

        // Post model requires 'admin' role approval via MakerCheckerConfigurable
        $approved = MakerChecker::approve($request, null, 'admin');

        $this->assertEquals(RequestStatus::APPROVED, $approved->status);
        $this->assertEquals($this->checker->id, $approved->checker_id);
    }

    public function test_facade_approve_with_explicit_user(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // No need to switch auth - pass checker explicitly
        // Post model requires 'admin' role approval via MakerCheckerConfigurable
        $approved = MakerChecker::approve($request, $this->checker, 'admin');

        $this->assertEquals(RequestStatus::APPROVED, $approved->status);
        $this->assertEquals($this->checker->id, $approved->checker_id);
    }

    public function test_facade_reject_with_authenticated_user(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $this->actingAs($this->checker);

        $rejected = MakerChecker::reject($request, null, 'Not approved');

        $this->assertEquals(RequestStatus::REJECTED, $rejected->status);
        $this->assertEquals('Not approved', $rejected->remarks);
    }

    public function test_facade_cancel_with_authenticated_user(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Maker cancels their own request
        $cancelled = MakerChecker::cancel($request, null, 'Changed my mind');

        $this->assertEquals(RequestStatus::CANCELLED, $cancelled->status);
        $this->assertEquals('Changed my mind', $cancelled->remarks);
    }

    public function test_model_approve_method(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $this->actingAs($this->checker);

        // Post model requires 'admin' role approval via MakerCheckerConfigurable
        $result = $request->approve(null, 'admin');

        $this->assertSame($request, $result);
        $this->assertEquals(RequestStatus::APPROVED, $request->fresh()->status);
    }

    public function test_model_approve_with_explicit_user(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $request->approve($this->checker, 'admin', 'Looks good');

        $this->assertEquals(RequestStatus::APPROVED, $request->fresh()->status);
    }

    public function test_model_reject_method(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $this->actingAs($this->checker);

        $result = $request->reject(null, 'Rejected for testing');

        $this->assertSame($request, $result);
        $this->assertEquals(RequestStatus::REJECTED, $request->fresh()->status);
        $this->assertEquals('Rejected for testing', $request->fresh()->remarks);
    }

    public function test_model_cancel_method(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $result = $request->cancel(null, 'Cancelled');

        $this->assertSame($request, $result);
        $this->assertEquals(RequestStatus::CANCELLED, $request->fresh()->status);
    }

    public function test_model_methods_are_chainable(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        $this->actingAs($this->checker);

        // approve() returns $this, so we can chain
        // Post model requires 'admin' role approval via MakerCheckerConfigurable
        $this->assertInstanceOf(MakerCheckerRequest::class, $request->approve(null, 'admin'));
    }
}
