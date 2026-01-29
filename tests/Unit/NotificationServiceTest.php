<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Moffhub\MakerChecker\Contracts\ApproverResolver;
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

        // Enable notifications for these tests
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

    public function test_notifications_are_disabled_by_default(): void
    {
        config(['maker-checker.notifications.enabled' => false]);

        Notification::fake();

        $this->actingAs($this->maker);
        MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        Notification::assertNothingSent();
    }

    public function test_pending_approval_notification_sent_when_enabled(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Should notify users with 'admin' role (required by Post model)
        Notification::assertSentTo(
            [$this->approver, $this->approver2],
            PendingApprovalNotification::class,
            function ($notification) use ($request) {
                return $notification->request->id === $request->id;
            }
        );
    }

    public function test_maker_not_notified_of_pending_approval(): void
    {
        // Make maker also an admin
        $this->maker->update(['role' => 'admin']);

        Notification::fake();

        $this->actingAs($this->maker);
        MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Maker should not receive notification even if they have approver role
        Notification::assertNotSentTo($this->maker, PendingApprovalNotification::class);
    }

    public function test_approved_notification_sent_to_maker(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Clear fake to only track approval notification
        Notification::fake();

        MakerChecker::approve($request, $this->approver, 'admin');

        Notification::assertSentTo(
            $this->maker,
            RequestApprovedNotification::class,
            function ($notification) use ($request) {
                return $notification->request->id === $request->id;
            }
        );
    }

    public function test_rejected_notification_sent_to_maker(): void
    {
        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Clear fake to only track rejection notification
        Notification::fake();

        MakerChecker::reject($request, $this->approver, 'Not approved');

        Notification::assertSentTo(
            $this->maker,
            RequestRejectedNotification::class,
            function ($notification) use ($request) {
                return $notification->request->id === $request->id
                    && $notification->request->remarks === 'Not approved';
            }
        );
    }

    public function test_maker_notification_can_be_disabled(): void
    {
        config(['maker-checker.notifications.notify_maker' => false]);

        Notification::fake();

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        Notification::fake();

        MakerChecker::approve($request, $this->approver, 'admin');

        Notification::assertNotSentTo($this->maker, RequestApprovedNotification::class);
    }

    public function test_sequential_notification_only_notifies_first_role(): void
    {
        config(['maker-checker.notifications.sequential' => true]);

        // Create request requiring multiple roles
        $this->actingAs($this->maker);

        $editor = User::create([
            'name' => 'Editor',
            'email' => 'editor@example.com',
            'role' => 'editor',
        ]);

        Notification::fake();

        // Create request with multi-role requirements
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['editor' => 1, 'admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Only editor should be notified first (not admins)
        Notification::assertSentTo($editor, PendingApprovalNotification::class);
        Notification::assertNotSentTo($this->approver, PendingApprovalNotification::class);
    }

    public function test_notify_next_approvers_after_partial_approval(): void
    {
        config(['maker-checker.notifications.sequential' => true]);

        $editor = User::create([
            'name' => 'Editor',
            'email' => 'editor@example.com',
            'role' => 'editor',
        ]);

        // Fake notifications from the start to avoid database issues
        Notification::fake();

        $this->actingAs($this->maker);

        // Create request with multi-role requirements
        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Test', 'user_id' => $this->maker->id])
            ->withApprovals(['editor' => 1, 'admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Editor approves
        MakerChecker::approve($request, $editor, 'editor');

        // Reset notification fake to only track next approvers
        Notification::fake();

        // Manually notify next approvers
        MakerChecker::notifyNextApprovers($request);

        // Now admins should be notified
        Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
    }

    public function test_manual_notify_approvers(): void
    {
        config(['maker-checker.notifications.enabled' => false]);

        $this->actingAs($this->maker);
        $request = MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        // Re-enable and manually trigger
        config(['maker-checker.notifications.enabled' => true]);
        Notification::fake();

        MakerChecker::notifyApprovers($request);

        Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
    }

    public function test_notification_service_can_be_accessed(): void
    {
        $service = MakerChecker::notifications();

        $this->assertInstanceOf(NotificationService::class, $service);
    }

    public function test_custom_approver_resolver_can_be_used(): void
    {
        // Create a custom resolver that returns specific users
        $customResolver = new class implements ApproverResolver
        {
            public function getApproversForRole(\Moffhub\MakerChecker\Models\MakerCheckerRequest $request, string $role): \Illuminate\Support\Collection
            {
                // Only return users with email containing 'approver'
                return User::where('email', 'like', '%approver%')->get();
            }

            public function getAllApprovers(\Moffhub\MakerChecker\Models\MakerCheckerRequest $request): \Illuminate\Support\Collection
            {
                return $this->getApproversForRole($request, 'any');
            }
        };

        $this->app->bind(ApproverResolver::class, fn() => $customResolver);

        Notification::fake();

        $this->actingAs($this->maker);
        MakerChecker::create(Post::class, [
            'title' => 'Test',
            'user_id' => $this->maker->id,
        ]);

        Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
        Notification::assertSentTo($this->approver2, PendingApprovalNotification::class);
    }
}
