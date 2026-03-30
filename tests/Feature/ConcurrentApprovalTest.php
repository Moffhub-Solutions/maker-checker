<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Exceptions\RequestCouldNotBeProcessed;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class ConcurrentApprovalTest extends BaseTestCase
{
    public function test_two_users_approving_same_single_approval_request_second_fails(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver1 = User::create(['name' => 'Approver 1', 'email' => 'approver1@example.com', 'role' => 'admin']);
        $approver2 = User::create(['name' => 'Approver 2', 'email' => 'approver2@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Concurrent Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        // First approval succeeds
        $approvedRequest = MakerChecker::approve($request, $approver1, 'admin');
        $this->assertEquals(RequestStatus::APPROVED, $approvedRequest->status);

        // Second approval on the same (now stale) request object should fail
        // because the request is no longer actionable
        $this->expectException(RequestCannotBeChecked::class);

        MakerChecker::approve($request->fresh(), $approver2, 'admin');
    }

    public function test_approval_after_request_has_been_fulfilled(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver1 = User::create(['name' => 'Approver 1', 'email' => 'approver1@example.com', 'role' => 'admin']);
        $approver2 = User::create(['name' => 'Approver 2', 'email' => 'approver2@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Fulfilled Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        // First approval fulfills the request
        MakerChecker::approve($request, $approver1, 'admin');

        // Re-fetch to get the latest state
        $freshRequest = MakerCheckerRequest::find($request->id);

        // If request was deleted on completion, it won't exist
        if ($freshRequest === null) {
            $this->assertTrue(true, 'Request was deleted after fulfillment');

            return;
        }

        // If it still exists, it should not be actionable
        $this->assertFalse($freshRequest->isActionable());

        $this->expectException(RequestCannotBeChecked::class);
        MakerChecker::approve($freshRequest, $approver2, 'admin');
    }

    public function test_approval_after_request_has_been_cancelled(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Cancelled Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        // Cancel the request first
        MakerChecker::cancel($request, $maker, 'No longer needed');

        // Attempt to approve the cancelled request
        $this->expectException(RequestCannotBeChecked::class);

        MakerChecker::approve($request->fresh(), $approver, 'admin');
    }

    public function test_rejection_after_request_has_been_cancelled(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $rejector = User::create(['name' => 'Rejector', 'email' => 'rejector@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Cancelled Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        MakerChecker::cancel($request, $maker);

        $this->expectException(RequestCannotBeChecked::class);
        MakerChecker::reject($request->fresh(), $rejector, 'Too late');
    }

    public function test_concurrent_partial_approvals_with_multi_approval_request(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver1 = User::create(['name' => 'Approver 1', 'email' => 'approver1@example.com', 'role' => 'admin']);
        $approver2 = User::create(['name' => 'Approver 2', 'email' => 'approver2@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Multi Approval', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 2])
            ->save();

        // First approval -> partial
        $result = MakerChecker::approve($request, $approver1, 'admin');
        $this->assertEquals(RequestStatus::PARTIALLY_APPROVED, $result->status);

        // Second approval -> approved
        $result = MakerChecker::approve($request->fresh(), $approver2, 'admin');
        $this->assertEquals(RequestStatus::APPROVED, $result->status);
    }

    public function test_same_approver_cannot_approve_twice(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Double Approve', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 2])
            ->save();

        // First approval
        MakerChecker::approve($request, $approver, 'admin');

        // Same approver tries again - should fail
        $this->expectException(RequestCouldNotBeProcessed::class);
        MakerChecker::approve($request->fresh(), $approver, 'admin');
    }

    public function test_lockforupdate_prevents_race_condition_on_status_check(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Lock Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        // Manually change status to approved outside the lock to simulate race condition
        MakerCheckerRequest::withoutEvents(function () use ($request) {
            MakerCheckerRequest::where('id', $request->id)->update(['status' => RequestStatus::APPROVED->value]);
        });

        // The approve method re-fetches inside the transaction with lockForUpdate
        // so it should detect the already-approved status
        $this->expectException(RequestCannotBeChecked::class);
        MakerChecker::approve($request, $approver, 'admin');
    }
}
