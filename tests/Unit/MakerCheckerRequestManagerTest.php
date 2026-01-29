<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestCancelled;
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeCancelled;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;
use Moffhub\MakerChecker\Tests\Fixtures\TestExecutable;

class MakerCheckerRequestManagerTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestExecutable::reset();
    }

    public function test_can_create_request_via_facade(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->description('Create a new post')
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Test content', 'user_id' => $maker->id])
            ->save();

        $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        $this->assertEquals(RequestType::CREATE, $request->type);
        $this->assertEquals(RequestStatus::PENDING, $request->status);
        $this->assertEquals('Create a new post', $request->description);

        Event::assertDispatched(RequestInitiated::class);
    }

    public function test_can_approve_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $approver = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        $approvedRequest = MakerChecker::approve($request, $approver, 'admin', 'Looks good');

        $this->assertEquals(RequestStatus::APPROVED, $approvedRequest->status);
        $this->assertEquals('Looks good', $approvedRequest->remarks);
        $this->assertNotNull($approvedRequest->checked_at);

        Event::assertDispatched(RequestApproved::class);
    }

    public function test_partial_approval_when_threshold_not_met(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $approver1 = User::create([
            'name' => 'Admin 1',
            'email' => 'admin1@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 2])
            ->save();

        $partialRequest = MakerChecker::approve($request, $approver1, 'admin');

        $this->assertEquals(RequestStatus::PARTIALLY_APPROVED, $partialRequest->status);
        $this->assertTrue($partialRequest->isPartiallyApproved());
        $this->assertTrue($partialRequest->isActionable());

        Event::assertNotDispatched(RequestApproved::class);
    }

    public function test_can_reject_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $rejector = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        $rejectedRequest = MakerChecker::reject($request, $rejector, 'Not approved');

        $this->assertEquals(RequestStatus::REJECTED, $rejectedRequest->status);
        $this->assertEquals('Not approved', $rejectedRequest->remarks);
        $this->assertNotNull($rejectedRequest->checked_at);

        Event::assertDispatched(RequestRejected::class);
    }

    public function test_can_cancel_request_by_maker(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        $cancelledRequest = MakerChecker::cancel($request, $maker, 'Changed my mind');

        $this->assertEquals(RequestStatus::CANCELLED, $cancelledRequest->status);
        $this->assertEquals('Changed my mind', $cancelledRequest->remarks);

        Event::assertDispatched(RequestCancelled::class);
    }

    public function test_cannot_cancel_request_by_non_maker(): void
    {
        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $other = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        $this->expectException(RequestCannotBeCancelled::class);

        MakerChecker::cancel($request, $other);
    }

    public function test_maker_cannot_approve_own_request(): void
    {
        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        $this->expectException(RequestCannotBeChecked::class);

        MakerChecker::approve($request, $maker);
    }

    public function test_cannot_approve_already_approved_request(): void
    {
        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $approver = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->save();

        MakerChecker::approve($request, $approver);

        $this->expectException(RequestCannotBeChecked::class);

        MakerChecker::approve($request->fresh(), $approver);
    }

    public function test_create_update_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original content',
            'user_id' => $maker->id,
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toUpdate($post, ['title' => 'Updated Title'])
            ->save();

        $this->assertEquals(RequestType::UPDATE, $request->type);
        $this->assertEquals($post->id, $request->subject_id);
        $this->assertEquals(Post::class, $request->subject_type);
        $this->assertEquals(['title' => 'Updated Title'], $request->payload);
    }

    public function test_create_delete_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $post = Post::create([
            'title' => 'To Be Deleted',
            'content' => 'Content',
            'user_id' => $maker->id,
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toDelete($post)
            ->save();

        $this->assertEquals(RequestType::DELETE, $request->type);
        $this->assertEquals($post->id, $request->subject_id);
    }

    public function test_create_execute_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toExecute(TestExecutable::class, ['action_id' => 123])
            ->save();

        $this->assertEquals(RequestType::EXECUTE, $request->type);
        $this->assertEquals(TestExecutable::class, $request->executable);
        $this->assertEquals(['action_id' => 123], $request->payload);
    }

    public function test_execute_request_runs_executable_on_approval(): void
    {
        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $approver = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toExecute(TestExecutable::class, ['action_id' => 456])
            ->withApprovals(['default' => 1])
            ->save();

        $this->assertFalse(TestExecutable::$executed);

        MakerChecker::approve($request, $approver);

        $this->assertTrue(TestExecutable::$executed);
        $this->assertNotNull(TestExecutable::$lastRequest);
        $this->assertEquals(['action_id' => 456], TestExecutable::$lastRequest->payload);
    }

    public function test_auto_resolves_approvals_from_config(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Post implements MakerCheckerConfigurable with approvals
        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        // Should auto-resolve from Post::makerCheckerApprovals()
        $this->assertEquals(['admin' => 1], $request->required_approvals);
    }

    public function test_explicit_approvals_override_config(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['custom_role' => 3])
            ->save();

        $this->assertEquals(['custom_role' => 3], $request->required_approvals);
    }

    public function test_auto_generates_description(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Post implements makerCheckerDescription
        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'My Awesome Post', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        $this->assertEquals('Create post: My Awesome Post', $request->description);
    }

    public function test_explicit_description_override(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->description('Custom description')
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        $this->assertEquals('Custom description', $request->description);
    }

    public function test_request_with_team_id(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'team_id' => 5,
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id], [], 5)
            ->save();

        $this->assertEquals(5, $request->team_id);
    }

    public function test_unique_by_fields(): void
    {
        Event::fake();
        $this->app['config']->set('maker-checker.ensure_requests_are_unique', true);

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request1 = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content 1', 'user_id' => $maker->id])
            ->uniqueBy('title')
            ->save();

        $this->assertNotNull($request1);

        // Trying to create another request with the same title should fail
        $this->expectException(\Moffhub\MakerChecker\Exceptions\DuplicateRequestException::class);

        MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content 2', 'user_id' => $maker->id])
            ->uniqueBy('title')
            ->save();
    }

    public function test_hooks_are_stored_in_metadata(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->beforeApproval(function ($request) {
                // Hook registered
            })
            ->afterApproval(function ($request) {
                // Hook registered
            })
            ->save();

        // Verify hooks are stored in metadata
        $this->assertNotNull($request->metadata);
        $this->assertArrayHasKey('hooks', $request->metadata);
        $this->assertArrayHasKey('pre_approval', $request->metadata['hooks']);
        $this->assertArrayHasKey('post_approval', $request->metadata['hooks']);
    }

    public function test_rejection_hooks_are_stored_in_metadata(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->beforeRejection(function ($request) {
                // Hook registered
            })
            ->afterRejection(function ($request) {
                // Hook registered
            })
            ->save();

        // Verify hooks are stored in metadata
        $this->assertNotNull($request->metadata);
        $this->assertArrayHasKey('hooks', $request->metadata);
        $this->assertArrayHasKey('pre_rejection', $request->metadata['hooks']);
        $this->assertArrayHasKey('post_rejection', $request->metadata['hooks']);
    }

    public function test_whitelisted_email_can_approve_own_request(): void
    {
        $this->app['config']->set('maker-checker.whitelisted_emails', 'admin@example.com,super@example.com');

        $maker = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->save();

        // Whitelisted user can approve their own request
        $approvedRequest = MakerChecker::approve($request, $maker);

        $this->assertEquals(RequestStatus::APPROVED, $approvedRequest->status);
    }
}
