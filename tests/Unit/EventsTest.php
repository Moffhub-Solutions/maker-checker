<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Events\ApprovalDelegated;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestCancelled;
use Moffhub\MakerChecker\Events\RequestExpired;
use Moffhub\MakerChecker\Events\RequestFailed;
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Events\RequestRolledBack;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerDelegation;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Services\DelegationService;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class EventsTest extends BaseTestCase
{
    private User $maker;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maker = User::create([
            'name' => 'Maker User',
            'email' => 'maker@example.com',
        ]);

        $this->approver = User::create([
            'name' => 'Approver User',
            'email' => 'approver@example.com',
            'role' => 'admin',
        ]);
    }

    // -------------------------------------------------------
    // Construction tests: each event can be built with all props
    // -------------------------------------------------------

    public function test_request_initiated_carries_all_properties(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->save();

        Event::assertDispatched(RequestInitiated::class, function (RequestInitiated $e) use ($request) {
            $this->assertTrue($e->request->is($request));
            $this->assertTrue($e->maker->is($this->maker));
            $this->assertSame(Post::class, $e->subjectType);
            $this->assertSame('create', $e->actionType);
            $this->assertSame(['title' => 'Test', 'content' => 'Body', 'user_id' => $this->maker->id], $e->payloadSummary);

            return true;
        });
    }

    public function test_request_approved_carries_all_properties(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Approved', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        Event::fake(); // reset
        MakerChecker::approve($request, $this->approver, 'admin', 'LGTM');

        Event::assertDispatched(RequestApproved::class, function (RequestApproved $e) use ($request) {
            $this->assertTrue($e->request->is($request));
            $this->assertTrue($e->maker->is($this->maker));
            $this->assertTrue($e->checker->is($this->approver));
            $this->assertSame('create', $e->actionType);
            $this->assertSame(1, $e->approvalCount);
            $this->assertSame(1, $e->requiredCount);

            return true;
        });
    }

    public function test_request_rejected_carries_all_properties(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Rejected', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->save();

        Event::fake();
        MakerChecker::reject($request, $this->approver, 'Bad data');

        Event::assertDispatched(RequestRejected::class, function (RequestRejected $e) use ($request) {
            $this->assertTrue($e->request->is($request));
            $this->assertTrue($e->maker->is($this->maker));
            $this->assertTrue($e->checker->is($this->approver));
            $this->assertSame('Bad data', $e->rejectionReason);

            return true;
        });
    }

    public function test_request_cancelled_carries_all_properties(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Cancelled', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->save();

        Event::fake();
        MakerChecker::cancel($request, $this->maker, 'Nevermind');

        Event::assertDispatched(RequestCancelled::class, function (RequestCancelled $e) use ($request) {
            $this->assertTrue($e->request->is($request));
            $this->assertTrue($e->maker->is($this->maker));
            $this->assertSame('Nevermind', $e->cancellationReason);

            return true;
        });
    }

    public function test_request_failed_carries_all_properties(): void
    {
        $request = new MakerCheckerRequest;
        $request->status = RequestStatus::PENDING;
        $exception = new \RuntimeException('Something went wrong');

        $event = new RequestFailed($request, $exception);

        $this->assertSame($request, $event->request);
        $this->assertSame($exception, $event->exception);
        $this->assertSame('Something went wrong', $event->errorMessage);
    }

    public function test_request_rolled_back_carries_all_properties(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Rollback Me', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        Event::fake();
        MakerChecker::approve($request, $this->approver, 'admin');

        Event::fake();
        MakerChecker::rollback($request->fresh(), $this->approver, 'Undo it');

        Event::assertDispatched(RequestRolledBack::class, function (RequestRolledBack $e) use ($request) {
            $this->assertTrue($e->request->is($request));
            $this->assertSame('Undo it', $e->rollbackReason);

            return true;
        });
    }

    public function test_request_expired_can_be_constructed(): void
    {
        $request = new MakerCheckerRequest;
        $request->status = RequestStatus::EXPIRED;
        $maker = new User(['name' => 'Test', 'email' => 'test@test.com']);

        $now = Carbon::now();
        $event = new RequestExpired($request, $maker, $now, 3);

        $this->assertSame($request, $event->request);
        $this->assertSame($maker, $event->maker);
        $this->assertSame($now, $event->expiredAt);
        $this->assertSame(3, $event->pendingApproverCount);
    }

    public function test_approval_delegated_can_be_constructed(): void
    {
        $delegator = new User(['name' => 'Boss', 'email' => 'boss@test.com']);
        $delegatee = new User(['name' => 'Deputy', 'email' => 'deputy@test.com']);
        $expiresAt = Carbon::now()->addWeek();

        $delegation = new MakerCheckerDelegation;

        $event = new ApprovalDelegated($delegation, $delegator, $delegatee, 'approvals', $expiresAt);

        $this->assertSame($delegation, $event->delegation);
        $this->assertSame($delegator, $event->delegator);
        $this->assertSame($delegatee, $event->delegatee);
        $this->assertSame('approvals', $event->scope);
        $this->assertSame($expiresAt, $event->expiresAt);
    }

    // -------------------------------------------------------
    // Dispatch integration tests
    // -------------------------------------------------------

    public function test_request_expired_fires_in_expire_command(): void
    {
        Event::fake();
        $this->app['config']->set('maker-checker.request_expiration_in_minutes', 60);

        // Create an overdue request
        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Overdue', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->save();

        // Backdate the created_at
        MakerCheckerRequest::withoutEvents(function () use ($request) {
            $request->update(['created_at' => Carbon::now()->subMinutes(120)]);
        });

        Event::fake(); // reset so we only capture the expire events

        $this->artisan('maker-checker:expire-overdue')
            ->assertExitCode(0);

        Event::assertDispatched(RequestExpired::class, function (RequestExpired $e) use ($request) {
            return $e->request->is($request);
        });
    }

    public function test_approval_delegated_fires_on_delegation_create(): void
    {
        Event::fake();

        $delegate = User::create([
            'name' => 'Delegate',
            'email' => 'delegate@example.com',
        ]);

        $service = app(DelegationService::class);
        $delegation = $service->create($this->maker, $delegate, 'test-scope', Carbon::now()->addDays(7));

        Event::assertDispatched(ApprovalDelegated::class, function (ApprovalDelegated $e) use ($delegation) {
            $this->assertSame($delegation->id, $e->delegation->id);
            $this->assertSame('test-scope', $e->scope);

            return true;
        });
    }

    // -------------------------------------------------------
    // Existing dispatch behaviour still works
    // -------------------------------------------------------

    public function test_existing_initiate_event_still_dispatched(): void
    {
        Event::fake();

        MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Initiated', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->save();

        Event::assertDispatched(RequestInitiated::class);
    }

    public function test_existing_approve_event_still_dispatched(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Approve', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        Event::fake();
        MakerChecker::approve($request, $this->approver, 'admin');

        Event::assertDispatched(RequestApproved::class);
    }

    public function test_existing_reject_event_still_dispatched(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Reject', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->save();

        Event::fake();
        MakerChecker::reject($request, $this->approver, 'Nope');

        Event::assertDispatched(RequestRejected::class);
    }

    public function test_existing_cancel_event_still_dispatched(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Cancel', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->save();

        Event::fake();
        MakerChecker::cancel($request, $this->maker);

        Event::assertDispatched(RequestCancelled::class);
    }

    public function test_existing_rollback_event_still_dispatched(): void
    {
        Event::fake();

        $request = MakerChecker::request()
            ->madeBy($this->maker)
            ->toCreate(Post::class, ['title' => 'Roll', 'content' => 'Body', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        Event::fake();
        MakerChecker::approve($request, $this->approver, 'admin');

        Event::fake();
        MakerChecker::rollback($request->fresh(), $this->approver, 'Undo');

        Event::assertDispatched(RequestRolledBack::class);
    }
}
