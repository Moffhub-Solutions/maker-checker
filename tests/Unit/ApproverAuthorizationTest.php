<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Exceptions\UnauthorizedApproverException;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class ApproverAuthorizationTest extends BaseTestCase
{
    public function test_unauthorized_user_cannot_approve_role_based_request(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        // Create an actual admin so the resolver returns a non-empty collection
        User::create(['name' => 'Real Admin', 'email' => 'realadmin@example.com', 'role' => 'admin']);
        $unauthorizedUser = User::create([
            'name' => 'Unauthorized',
            'email' => 'unauthorized@example.com',
            'role' => 'viewer', // Not an admin
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Auth Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        $this->expectException(UnauthorizedApproverException::class);
        MakerChecker::approve($request, $unauthorizedUser, 'admin');
    }

    public function test_authorized_role_based_approver_can_approve(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Auth Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        $result = MakerChecker::approve($request, $admin, 'admin');
        $this->assertEquals(RequestStatus::APPROVED, $result->status);
    }

    public function test_user_specific_approver_can_approve(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $specificUser = User::create([
            'name' => 'Specific Approver',
            'email' => 'specific@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Auth Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withRoleAndUserApprovals([], ['specific@example.com'])
            ->save();

        $result = MakerChecker::approve($request, $specificUser);
        $this->assertEquals(RequestStatus::APPROVED, $result->status);
    }

    public function test_whitelisted_email_bypasses_approver_check(): void
    {
        $this->app['config']->set('maker-checker.whitelisted_emails', 'super@example.com');

        $maker = User::create(['name' => 'Maker', 'email' => 'super@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Whitelist Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        // Whitelisted user can approve their own request (bypasses both maker=checker and approver checks)
        $result = MakerChecker::approve($request, $maker, 'admin');
        $this->assertEquals(RequestStatus::APPROVED, $result->status);
    }

    public function test_empty_resolver_result_allows_approval_backwards_compat(): void
    {
        // When no user model is configured, the resolver returns empty collection
        // This should gracefully allow approval (backwards compatibility)
        $this->app['config']->set('maker-checker.notifications.user_model', null);
        $this->app['config']->set('auth.providers.users.model', null);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Compat Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->save();

        $result = MakerChecker::approve($request, $approver);
        $this->assertEquals(RequestStatus::APPROVED, $result->status);
    }

    public function test_non_required_user_cannot_approve_user_specific_request(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $requiredUser = User::create([
            'name' => 'Required',
            'email' => 'required@example.com',
            'role' => 'admin',
        ]);
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'User Specific', 'content' => 'Content', 'user_id' => $maker->id])
            ->withRoleAndUserApprovals([], ['required@example.com'])
            ->save();

        // The non-required user should not be able to approve
        $this->expectException(RequestCannotBeChecked::class);
        MakerChecker::approve($request, $otherUser);
    }
}
