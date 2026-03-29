<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Exceptions\RequestCouldNotBeInitiated;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Notifications\PendingApprovalNotification;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class UserApprovalTest extends BaseTestCase
{
    private User $maker;

    private User $specificApprover;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['maker-checker.notifications.enabled' => true]);
        config(['maker-checker.notifications.channels' => ['database']]);
        config(['maker-checker.notifications.user_model' => User::class]);

        $this->maker = User::create([
            'name' => 'Maker User',
            'email' => 'maker@example.com',
            'role' => 'editor',
        ]);

        $this->specificApprover = User::create([
            'name' => 'Specific Approver',
            'email' => 'specific@example.com',
            'role' => 'manager',
        ]);

        $this->otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_request_can_require_specific_user_approval_by_email(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['specific@example.com'])
            ->madeBy($this->maker)
            ->save();

        $this->assertTrue($request->requiresUserApprovals());
        $this->assertContains('specific@example.com', $request->getPendingUsers());
    }

    public function test_request_can_require_both_role_and_user_approval(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withRoleAndUserApprovals(
                ['admin' => 1],
                ['specific@example.com']
            )
            ->madeBy($this->maker)
            ->save();

        $this->assertTrue($request->requiresUserApprovals());
        $this->assertArrayHasKey('admin', $request->getPendingRoles());
        $this->assertContains('specific@example.com', $request->getPendingUsers());
    }

    public function test_specific_user_can_approve_when_required(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['specific@example.com'])
            ->madeBy($this->maker)
            ->save();

        // Specific approver should be able to approve
        MakerChecker::approve($request, $this->specificApprover, 'user');

        $this->assertTrue($request->fresh()->isApproved());
    }

    public function test_non_required_user_cannot_approve_user_specific_request(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['specific@example.com'])
            ->madeBy($this->maker)
            ->save();

        $this->expectException(RequestCannotBeChecked::class);
        $this->expectExceptionMessage('not authorized');

        // Other user should NOT be able to approve
        MakerChecker::approve($request, $this->otherUser);
    }

    public function test_request_requires_all_specified_users_to_approve(): void
    {
        Notification::fake();

        $anotherApprover = User::create([
            'name' => 'Another Approver',
            'email' => 'another@example.com',
            'role' => 'manager',
        ]);

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['specific@example.com', 'another@example.com'])
            ->madeBy($this->maker)
            ->save();

        // First user approves
        MakerChecker::approve($request, $this->specificApprover, 'user');
        $request->refresh();

        $this->assertFalse($request->isApproved());
        $this->assertTrue($request->isPartiallyApproved());

        // Second user approves
        MakerChecker::approve($request, $anotherApprover, 'user');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_request_fails_if_required_user_does_not_exist(): void
    {
        $this->actingAs($this->maker);

        $this->expectException(RequestCouldNotBeInitiated::class);
        $this->expectExceptionMessage('do not exist');

        MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['nonexistent@example.com'])
            ->madeBy($this->maker)
            ->save();
    }

    public function test_user_validation_can_be_disabled(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        // Should not throw even though user doesn't exist
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['nonexistent@example.com'], validateExistence: false)
            ->madeBy($this->maker)
            ->save();

        $this->assertNotNull($request->id);
    }

    public function test_mixed_role_and_user_approval_flow(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withRoleAndUserApprovals(
                ['admin' => 1],
                ['specific@example.com']
            )
            ->madeBy($this->maker)
            ->save();

        // Admin approves their role requirement
        MakerChecker::approve($request, $this->otherUser, 'admin');
        $request->refresh();

        $this->assertFalse($request->isApproved());
        $this->assertTrue($request->isPartiallyApproved());

        // Specific user still needs to approve
        $this->assertContains('specific@example.com', $request->getPendingUsers());

        // Specific user approves
        MakerChecker::approve($request, $this->specificApprover, 'user');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_manual_notifications_sent_to_specific_users(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['specific@example.com'])
            ->madeBy($this->maker)
            ->save();

        // Manually trigger notifications (package does not auto-send)
        MakerChecker::notifyApprovers($request);

        Notification::assertSentTo($this->specificApprover, PendingApprovalNotification::class);
        Notification::assertNotSentTo($this->otherUser, PendingApprovalNotification::class);
    }

    public function test_manual_notifications_sent_to_both_roles_and_specific_users(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withRoleAndUserApprovals(
                ['admin' => 1],
                ['specific@example.com']
            )
            ->madeBy($this->maker)
            ->save();

        // Manually trigger notifications (package does not auto-send)
        MakerChecker::notifyApprovers($request);

        // Both specific user and admin should be notified
        Notification::assertSentTo($this->specificApprover, PendingApprovalNotification::class);
        Notification::assertSentTo($this->otherUser, PendingApprovalNotification::class);
    }

    public function test_approval_records_user_email(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['specific@example.com'])
            ->madeBy($this->maker)
            ->save();

        MakerChecker::approve($request, $this->specificApprover, 'user');
        $request->refresh();

        $approvals = $request->getApprovers();
        $this->assertCount(1, $approvals);
        $this->assertEquals('specific@example.com', $approvals[0]['user_email']);
    }

    public function test_get_pending_users_returns_only_unapproved_users(): void
    {
        Notification::fake();

        $anotherApprover = User::create([
            'name' => 'Another Approver',
            'email' => 'another@example.com',
            'role' => 'manager',
        ]);

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove(['specific@example.com', 'another@example.com'])
            ->madeBy($this->maker)
            ->save();

        $this->assertCount(2, $request->getPendingUsers());

        MakerChecker::approve($request, $this->specificApprover, 'user');
        $request->refresh();

        $pendingUsers = $request->getPendingUsers();
        $this->assertCount(1, $pendingUsers);
        $this->assertContains('another@example.com', $pendingUsers);
        $this->assertNotContains('specific@example.com', $pendingUsers);
    }

    public function test_legacy_format_still_works(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        // Legacy format: ['role' => count]
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        $this->assertFalse($request->requiresUserApprovals());

        MakerChecker::approve($request, $this->otherUser, 'admin');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_user_approval_by_id(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->requiringUsersToApprove([(string) $this->specificApprover->id])
            ->madeBy($this->maker)
            ->save();

        $this->assertTrue($request->requiresUserApprovals());

        MakerChecker::approve($request, $this->specificApprover, 'user');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }
}
