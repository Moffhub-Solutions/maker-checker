<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class OrApprovalModeTest extends BaseTestCase
{
    private User $maker;

    private User $admin;

    private User $manager;

    private User $specificUser;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config(['maker-checker.notifications.enabled' => true]);
        config(['maker-checker.notifications.channels' => ['database']]);
        config(['maker-checker.notifications.user_model' => User::class]);

        $this->maker = User::create([
            'name' => 'Maker User',
            'email' => 'maker@example.com',
            'role' => 'editor',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->manager = User::create([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'role' => 'manager',
        ]);

        $this->specificUser = User::create([
            'name' => 'Specific User',
            'email' => 'specific@example.com',
            'role' => 'viewer',
        ]);
    }

    public function test_or_mode_approved_when_any_role_meets_threshold(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval(['admin' => 1, 'manager' => 1])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals('any', $request->getApprovalMode());

        // Admin approves — should satisfy the OR condition
        MakerChecker::approve($request, $this->admin, 'admin');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_or_mode_approved_by_second_role(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval(['admin' => 1, 'manager' => 1])
            ->madeBy($this->maker)
            ->save();

        // Manager approves — should also satisfy the OR condition
        MakerChecker::approve($request, $this->manager, 'manager');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_or_mode_with_users_approved_by_any_user(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval([
                'users' => ['admin@example.com', 'specific@example.com'],
            ])
            ->madeBy($this->maker)
            ->save();

        // Specific user approves — should satisfy the OR condition
        MakerChecker::approve($request, $this->specificUser, 'user');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_or_mode_with_roles_and_users_approved_by_role(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyRoleOrUserApproval(
                ['admin' => 1],
                ['specific@example.com']
            )
            ->madeBy($this->maker)
            ->save();

        // Admin approves via role — should satisfy the OR condition
        MakerChecker::approve($request, $this->admin, 'admin');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_or_mode_with_roles_and_users_approved_by_user(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyRoleOrUserApproval(
                ['admin' => 1],
                ['specific@example.com']
            )
            ->madeBy($this->maker)
            ->save();

        // Specific user approves — should satisfy the OR condition
        MakerChecker::approve($request, $this->specificUser, 'user');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_or_mode_not_met_without_any_approval(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval(['admin' => 1, 'manager' => 1])
            ->madeBy($this->maker)
            ->save();

        $this->assertFalse($request->hasMetApprovalThreshold());
    }

    public function test_or_mode_with_higher_role_count_needs_multiple(): void
    {
        $this->actingAs($this->maker);

        $secondAdmin = User::create([
            'name' => 'Second Admin',
            'email' => 'admin2@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval(['admin' => 2, 'manager' => 1])
            ->madeBy($this->maker)
            ->save();

        // One admin is not enough for the admin threshold
        MakerChecker::approve($request, $this->admin, 'admin');
        $request->refresh();

        $this->assertFalse($request->isApproved());
        $this->assertTrue($request->isPartiallyApproved());

        // Second admin meets the threshold — OR satisfied
        MakerChecker::approve($request, $secondAdmin, 'admin');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_or_mode_manager_can_shortcircuit_admin_threshold(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval(['admin' => 2, 'manager' => 1])
            ->madeBy($this->maker)
            ->save();

        // One admin not enough
        MakerChecker::approve($request, $this->admin, 'admin');
        $request->refresh();
        $this->assertFalse($request->isApproved());

        // But a manager can satisfy the OR condition
        MakerChecker::approve($request, $this->manager, 'manager');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_and_mode_still_requires_all_roles(): void
    {
        $this->actingAs($this->maker);

        // Default mode (AND) — both admin AND manager required
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['roles' => ['admin' => 1, 'manager' => 1]])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals('all', $request->getApprovalMode());

        MakerChecker::approve($request, $this->admin, 'admin');
        $request->refresh();

        $this->assertFalse($request->isApproved());
        $this->assertTrue($request->isPartiallyApproved());

        MakerChecker::approve($request, $this->manager, 'manager');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_with_approval_mode_method_on_builder(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['roles' => ['admin' => 1, 'manager' => 1]])
            ->withApprovalMode('any')
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals('any', $request->getApprovalMode());

        // Single role should satisfy OR
        MakerChecker::approve($request, $this->manager, 'manager');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }

    public function test_or_mode_get_pending_roles_returns_unfulfilled_roles(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval(['admin' => 1, 'manager' => 1])
            ->madeBy($this->maker)
            ->save();

        $pending = $request->getPendingRoles();
        $this->assertArrayHasKey('admin', $pending);
        $this->assertArrayHasKey('manager', $pending);

        MakerChecker::approve($request, $this->admin, 'admin');
        $request->refresh();

        // After admin approves, only manager is pending (but request is already approved)
        $pending = $request->getPendingRoles();
        $this->assertArrayNotHasKey('admin', $pending);
        $this->assertArrayHasKey('manager', $pending);
    }

    public function test_or_mode_get_pending_users_returns_unapproved_users(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval([
                'users' => ['admin@example.com', 'specific@example.com'],
            ])
            ->madeBy($this->maker)
            ->save();

        $pending = $request->getPendingUsers();
        $this->assertCount(2, $pending);

        MakerChecker::approve($request, $this->admin, 'user');
        $request->refresh();

        $pending = $request->getPendingUsers();
        $this->assertCount(1, $pending);
        $this->assertContains('specific@example.com', $pending);
    }

    public function test_or_mode_unauthorized_user_blocked(): void
    {
        $this->actingAs($this->maker);

        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider@example.com',
            'role' => 'viewer',
        ]);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withAnyApproval([
                'users' => ['admin@example.com', 'specific@example.com'],
            ])
            ->madeBy($this->maker)
            ->save();

        $this->expectException(RequestCannotBeChecked::class);

        // Outsider is not in the required users list and there are no pending roles
        MakerChecker::approve($request, $outsider, 'user');
    }

    public function test_or_mode_invalid_mode_throws_exception(): void
    {
        $this->actingAs($this->maker);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Approval mode must be 'all' or 'any'");

        MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovalMode('invalid')
            ->madeBy($this->maker)
            ->save();
    }

    public function test_legacy_format_unaffected_by_or_feature(): void
    {
        $this->actingAs($this->maker);

        // Legacy format: ['role' => count] — no mode key
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals('all', $request->getApprovalMode());

        MakerChecker::approve($request, $this->admin, 'admin');
        $request->refresh();

        $this->assertTrue($request->isApproved());
    }
}
