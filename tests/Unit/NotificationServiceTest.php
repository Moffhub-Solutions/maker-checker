<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestCancelled;
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Notifications\PendingApprovalNotification;
use Moffhub\MakerChecker\Notifications\RequestApprovedNotification;
use Moffhub\MakerChecker\Notifications\RequestRejectedNotification;
use Moffhub\MakerChecker\Services\NotificationService;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class NotificationServiceTest extends BaseTestCase
{
    private User $maker;

    private User $approver;

    private User $approver2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['maker-checker.notifications.enabled' => true]);
        config(['maker-checker.notifications.channels' => ['database']]);
        config(['maker-checker.notifications.user_model' => User::class]);
        config(['maker-checker.notifications.role_attribute' => 'role']);

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

        $this->approver2 = User::create([
            'name' => 'Second Approver',
            'email' => 'approver2@example.com',
            'role' => 'admin',
        ]);
    }

    // =========================================================================
    // Events are dispatched (package responsibility)
    // =========================================================================

    public function test_request_initiated_event_dispatched_on_create(): void
    {
        Event::fake([RequestInitiated::class]);

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        Event::assertDispatched(RequestInitiated::class, function ($event) use ($request) {
            return $event->request->id === $request->id;
        });
    }

    public function test_request_approved_event_dispatched_on_approve(): void
    {
        Event::fake([RequestApproved::class]);

        $this->actingAs($this->maker);
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        MakerChecker::approve($request, $this->approver, 'admin');

        Event::assertDispatched(RequestApproved::class, function ($event) use ($request) {
            return $event->request->id === $request->id;
        });
    }

    public function test_request_rejected_event_dispatched_on_reject(): void
    {
        Event::fake([RequestRejected::class]);

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        MakerChecker::reject($request, $this->approver, 'Not approved');

        Event::assertDispatched(RequestRejected::class, function ($event) use ($request) {
            return $event->request->id === $request->id;
        });
    }

    public function test_request_cancelled_event_dispatched_on_cancel(): void
    {
        Event::fake([RequestCancelled::class]);

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        MakerChecker::cancel($request, $this->maker, 'Changed my mind');

        Event::assertDispatched(RequestCancelled::class, function ($event) use ($request) {
            return $event->request->id === $request->id;
        });
    }

    public function test_no_notifications_sent_automatically(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Package should NOT send notifications automatically
        Notification::assertNothingSent();
    }

    public function test_no_notifications_on_approve(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        MakerChecker::approve($request, $this->approver, 'admin');

        Notification::assertNothingSent();
    }

    public function test_no_notifications_on_reject(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        MakerChecker::reject($request, $this->approver, 'Nope');

        Notification::assertNothingSent();
    }

    // =========================================================================
    // NotificationService works as an opt-in utility
    // =========================================================================

    public function test_notification_service_can_be_accessed(): void
    {
        $service = MakerChecker::notifications();
        $this->assertInstanceOf(NotificationService::class, $service);
    }

    public function test_manual_notify_pending_approval(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Manually trigger notification via the service
        MakerChecker::notifyApprovers($request);

        Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
    }

    public function test_manual_notify_approved(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        MakerChecker::approve($request, $this->approver, 'admin');

        // Manually notify maker
        MakerChecker::notifications()->notifyRequestApproved($request);

        Notification::assertSentTo($this->maker, RequestApprovedNotification::class);
    }

    public function test_manual_notify_rejected(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        MakerChecker::reject($request, $this->approver, 'Not approved');

        // Manually notify maker
        MakerChecker::notifications()->notifyRequestRejected($request);

        Notification::assertSentTo($this->maker, RequestRejectedNotification::class);
    }

    public function test_manual_notify_respects_enabled_flag(): void
    {
        config(['maker-checker.notifications.enabled' => false]);

        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Even manual call respects the enabled flag
        MakerChecker::notifyApprovers($request);

        Notification::assertNothingSent();
    }

    public function test_sequential_notification_only_notifies_first_role(): void
    {
        $editor = User::create([
            'name' => 'Editor',
            'email' => 'editor@example.com',
            'role' => 'editor',
        ]);

        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['editor' => 1, 'admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Manually trigger sequential notification
        MakerChecker::notifyApprovers($request, sequential: true);

        // Only editor should be notified (not admins)
        Notification::assertSentTo($editor, PendingApprovalNotification::class);
        Notification::assertNotSentTo($this->approver, PendingApprovalNotification::class);
    }

    public function test_notify_next_approvers_after_partial_approval(): void
    {
        $editor = User::create([
            'name' => 'Editor',
            'email' => 'editor@example.com',
            'role' => 'editor',
        ]);

        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['editor' => 1, 'admin' => 1])
            ->madeBy($this->maker)
            ->save();

        MakerChecker::approve($request, $editor, 'editor');

        Notification::fake();

        // Manually notify next approvers
        MakerChecker::notifyNextApprovers($request);

        Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
    }

    public function test_custom_approver_resolver_works_with_manual_notify(): void
    {
        $customResolver = new class implements ApproverResolver
        {
            public function getApproversForRole(\Moffhub\MakerChecker\Models\MakerCheckerRequest $request, string $role): \Illuminate\Support\Collection
            {
                return User::where('email', 'like', '%approver%')->get();
            }

            public function getAllApprovers(\Moffhub\MakerChecker\Models\MakerCheckerRequest $request): \Illuminate\Support\Collection
            {
                return $this->getApproversForRole($request, 'any');
            }

            public function getApproversByIdentifier(\Moffhub\MakerChecker\Models\MakerCheckerRequest $request, array $userIdentifiers): \Illuminate\Support\Collection
            {
                return User::whereIn('email', $userIdentifiers)->orWhereIn('id', $userIdentifiers)->get();
            }

            public function getApproverByIdentifier(string $identifier): ?\Illuminate\Database\Eloquent\Model
            {
                return User::where('email', $identifier)->orWhere('id', $identifier)->first();
            }

            public function userExists(string $identifier): bool
            {
                return $this->getApproverByIdentifier($identifier) !== null;
            }

            public function validateUsersExist(array $userIdentifiers): array
            {
                return array_filter($userIdentifiers, fn ($id) => !$this->userExists($id));
            }
        };

        $this->app->bind(ApproverResolver::class, fn () => $customResolver);

        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Manually notify using custom resolver
        MakerChecker::notifyApprovers($request);

        Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
        Notification::assertSentTo($this->approver2, PendingApprovalNotification::class);
    }
}
