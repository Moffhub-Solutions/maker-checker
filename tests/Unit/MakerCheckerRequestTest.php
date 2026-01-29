<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Exception;
use Illuminate\Support\Str;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class MakerCheckerRequestTest extends BaseTestCase
{
    /**
     * Create a test request with required fields.
     */
    protected function createRequest(array $attributes = []): MakerCheckerRequest
    {
        $maker = $attributes['maker'] ?? User::create([
            'name' => 'Test User',
            'email' => 'test'.Str::random(5).'@example.com',
        ]);

        $defaults = [
            'description' => 'Test request',
            'type' => RequestType::CREATE,
            'status' => RequestStatus::PENDING,
            'subject_type' => Post::class,
            'maker_type' => User::class,
            'maker_id' => $maker->id,
            'made_at' => now(),
        ];

        $request = new MakerCheckerRequest(array_merge($defaults, $attributes));
        $request->code = $attributes['code'] ?? (string) Str::uuid();
        $request->save();

        return $request;
    }

    public function test_can_create_maker_checker_request(): void
    {
        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $request = $this->createRequest([
            'code' => 'test-123',
            'maker' => $maker,
            'payload' => ['title' => 'Test Post', 'content' => 'Test content'],
        ]);

        $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        $this->assertEquals('test-123', $request->code);
        $this->assertEquals(RequestType::CREATE, $request->type);
        $this->assertEquals(RequestStatus::PENDING, $request->status);
    }

    public function test_request_status_checks(): void
    {
        $request = $this->createRequest();

        $this->assertTrue($request->isPending());
        $this->assertTrue($request->isActionable());
        $this->assertFalse($request->isApproved());
        $this->assertFalse($request->isRejected());
        $this->assertFalse($request->isFinalized());
    }

    public function test_request_type_checks(): void
    {
        $request = $this->createRequest();

        $this->assertTrue($request->isOfType(RequestType::CREATE));
        $this->assertFalse($request->isOfType(RequestType::UPDATE));
        $this->assertFalse($request->isOfType(RequestType::DELETE));
    }

    public function test_can_add_approval(): void
    {
        $approver = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'role' => 'admin',
        ]);

        $request = $this->createRequest([
            'required_approvals' => ['admin' => 1],
        ]);

        $request->addApproval($approver, 'admin');

        $this->assertEquals(1, $request->getApprovalCount());
        $this->assertCount(1, $request->getApprovers());
        $this->assertTrue($request->hasMetApprovalThreshold());
    }

    public function test_duplicate_approval_throws_exception(): void
    {
        $approver = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'role' => 'admin',
        ]);

        $request = $this->createRequest([
            'required_approvals' => ['admin' => 2],
        ]);

        $request->addApproval($approver, 'admin');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This approver has already approved the request.');

        $request->addApproval($approver, 'admin');
    }

    public function test_has_met_approval_threshold_with_multiple_roles(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $editor = User::create([
            'name' => 'Editor User',
            'email' => 'editor@example.com',
            'role' => 'editor',
        ]);

        $request = $this->createRequest([
            'type' => RequestType::UPDATE,
            'required_approvals' => ['admin' => 1, 'editor' => 1],
        ]);

        $this->assertFalse($request->hasMetApprovalThreshold());

        $request->addApproval($admin, 'admin');
        $this->assertFalse($request->hasMetApprovalThreshold());

        $request->addApproval($editor, 'editor');
        $this->assertTrue($request->hasMetApprovalThreshold());
    }

    public function test_get_pending_roles(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $request = $this->createRequest([
            'type' => RequestType::DELETE,
            'required_approvals' => ['admin' => 2, 'manager' => 1],
        ]);

        $pending = $request->getPendingRoles();
        $this->assertEquals(['admin' => 2, 'manager' => 1], $pending);

        $request->addApproval($admin, 'admin');
        $pending = $request->getPendingRoles();
        $this->assertEquals(['admin' => 1, 'manager' => 1], $pending);
    }

    public function test_scope_pending(): void
    {
        $this->createRequest(['code' => 'pending-1']);
        $this->createRequest([
            'code' => 'approved-1',
            'status' => RequestStatus::APPROVED,
        ]);

        $pending = MakerCheckerRequest::pending()->get();
        $this->assertCount(1, $pending);
        $this->assertEquals('pending-1', $pending->first()->code);
    }

    public function test_scope_actionable(): void
    {
        $this->createRequest(['code' => 'pending-1']);
        $this->createRequest([
            'code' => 'partial-1',
            'status' => RequestStatus::PARTIALLY_APPROVED,
        ]);
        $this->createRequest([
            'code' => 'approved-1',
            'status' => RequestStatus::APPROVED,
        ]);

        $actionable = MakerCheckerRequest::actionable()->get();
        $this->assertCount(2, $actionable);
    }

    public function test_scope_visible_to_with_admin_user(): void
    {
        $this->app['config']->set('maker-checker.view_any_permission', 'view_all_requests');

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $regularUser = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'role' => 'user',
        ]);

        $this->createRequest([
            'code' => 'admin-request',
            'maker' => $admin,
        ]);

        $this->createRequest([
            'code' => 'user-request',
            'maker' => $regularUser,
        ]);

        // Admin can see all requests
        $visibleToAdmin = MakerCheckerRequest::visibleTo($admin)->get();
        $this->assertCount(2, $visibleToAdmin);

        // Regular user can only see own requests
        $visibleToUser = MakerCheckerRequest::visibleTo($regularUser)->get();
        $this->assertCount(1, $visibleToUser);
        $this->assertEquals('user-request', $visibleToUser->first()->code);
    }

    public function test_scope_visible_to_with_team_id(): void
    {
        $user1 = User::create([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'team_id' => 1,
        ]);

        $user2 = User::create([
            'name' => 'User 2',
            'email' => 'user2@example.com',
            'team_id' => 1,
        ]);

        $user3 = User::create([
            'name' => 'User 3',
            'email' => 'user3@example.com',
            'team_id' => 2,
        ]);

        $this->createRequest([
            'code' => 'team1-request',
            'maker' => $user1,
            'team_id' => 1,
        ]);

        $this->createRequest([
            'code' => 'team2-request',
            'maker' => $user3,
            'team_id' => 2,
        ]);

        // User 1 can see team 1 requests
        $visibleToUser1 = MakerCheckerRequest::visibleTo($user1)->get();
        $this->assertCount(1, $visibleToUser1);
        $this->assertEquals('team1-request', $visibleToUser1->first()->code);

        // User 2 can also see team 1 requests (same team)
        $visibleToUser2 = MakerCheckerRequest::visibleTo($user2)->get();
        $this->assertCount(1, $visibleToUser2);

        // User 3 can see team 2 requests
        $visibleToUser3 = MakerCheckerRequest::visibleTo($user3)->get();
        $this->assertCount(1, $visibleToUser3);
        $this->assertEquals('team2-request', $visibleToUser3->first()->code);
    }

    public function test_finalized_statuses(): void
    {
        $finalized = RequestStatus::getFinalizedStatuses();

        $this->assertContains(RequestStatus::APPROVED, $finalized);
        $this->assertContains(RequestStatus::REJECTED, $finalized);
        $this->assertContains(RequestStatus::EXPIRED, $finalized);
        $this->assertContains(RequestStatus::FAILED, $finalized);
        $this->assertContains(RequestStatus::CANCELLED, $finalized);

        $this->assertNotContains(RequestStatus::PENDING, $finalized);
        $this->assertNotContains(RequestStatus::PARTIALLY_APPROVED, $finalized);
    }

    public function test_actionable_statuses(): void
    {
        $actionable = RequestStatus::getActionableStatuses();

        $this->assertContains(RequestStatus::PENDING, $actionable);
        $this->assertContains(RequestStatus::PARTIALLY_APPROVED, $actionable);

        $this->assertNotContains(RequestStatus::APPROVED, $actionable);
        $this->assertNotContains(RequestStatus::REJECTED, $actionable);
    }
}
