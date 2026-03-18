<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Notifications\ApprovalReminderNotification;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class ApprovalRemindersTest extends BaseTestCase
{
    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->user = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'role' => 'user',
        ]);
    }

    protected function createRequest(array $attributes = []): MakerCheckerRequest
    {
        $maker = $attributes['maker'] ?? $this->user;
        unset($attributes['maker']);

        $defaults = [
            'description' => 'Test request',
            'type' => RequestType::CREATE,
            'status' => RequestStatus::PENDING,
            'subject_type' => Post::class,
            'maker_type' => User::class,
            'maker_id' => $maker->id,
            'made_at' => now(),
            'payload' => ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id],
        ];

        $merged = array_merge($defaults, $attributes);
        $code = $merged['code'] ?? (string) Str::uuid();
        unset($merged['code']);

        $request = new MakerCheckerRequest($merged);
        $request->code = $code;
        $request->save();

        return $request;
    }

    public function test_command_disabled_when_reminders_not_enabled(): void
    {
        $this->app['config']->set('maker-checker.reminders.enabled', false);

        $this->artisan('maker-checker:send-reminders')
            ->expectsOutput('Reminders are disabled. Set maker-checker.reminders.enabled to true.')
            ->assertSuccessful();
    }

    public function test_command_sends_reminders_for_old_pending_requests(): void
    {
        Notification::fake();

        $this->app['config']->set('maker-checker.reminders.enabled', true);
        $this->app['config']->set('maker-checker.reminders.after_hours', 24);
        $this->app['config']->set('maker-checker.escalation.after_hours', 48);
        $this->app['config']->set('maker-checker.notifications.enabled', true);

        // Create an old pending request (30 hours ago)
        $request = $this->createRequest(['required_approvals' => ['admin' => 1]]);
        MakerCheckerRequest::where('id', $request->id)
            ->update(['created_at' => now()->subHours(30)]);

        $this->artisan('maker-checker:send-reminders')
            ->assertSuccessful();

        Notification::assertSentTo($this->admin, ApprovalReminderNotification::class);
    }

    public function test_command_sends_escalation_for_very_old_requests(): void
    {
        Notification::fake();

        $this->app['config']->set('maker-checker.reminders.enabled', true);
        $this->app['config']->set('maker-checker.reminders.after_hours', 24);
        $this->app['config']->set('maker-checker.escalation.after_hours', 48);
        $this->app['config']->set('maker-checker.notifications.enabled', true);

        // Create a very old pending request (72 hours ago)
        $request = $this->createRequest(['required_approvals' => ['admin' => 1]]);
        MakerCheckerRequest::where('id', $request->id)
            ->update(['created_at' => now()->subHours(72)]);

        $this->artisan('maker-checker:send-reminders')
            ->assertSuccessful();

        Notification::assertSentTo(
            $this->admin,
            fn(ApprovalReminderNotification $notification) => $notification->isEscalation === true
        );
    }

    public function test_command_does_not_send_for_recent_requests(): void
    {
        Notification::fake();

        $this->app['config']->set('maker-checker.reminders.enabled', true);
        $this->app['config']->set('maker-checker.reminders.after_hours', 24);
        $this->app['config']->set('maker-checker.escalation.after_hours', 48);

        // Create a recent pending request (1 hour ago)
        $this->createRequest(['required_approvals' => ['admin' => 1]]);

        $this->artisan('maker-checker:send-reminders')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_command_dry_run(): void
    {
        $this->app['config']->set('maker-checker.reminders.enabled', true);
        $this->app['config']->set('maker-checker.reminders.after_hours', 24);
        $this->app['config']->set('maker-checker.escalation.after_hours', 48);

        $request = $this->createRequest(['required_approvals' => ['admin' => 1]]);
        MakerCheckerRequest::where('id', $request->id)
            ->update(['created_at' => now()->subHours(30)]);

        $this->artisan('maker-checker:send-reminders', ['--dry-run' => true])
            ->assertSuccessful();
    }

    public function test_notification_via_channels(): void
    {
        $this->app['config']->set('maker-checker.notifications.channels', ['mail', 'database']);

        $request = $this->createRequest();
        $notification = new ApprovalReminderNotification($request, false);

        $channels = $notification->via($this->admin);
        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    public function test_notification_to_array(): void
    {
        $request = $this->createRequest();
        $notification = new ApprovalReminderNotification($request, false);

        $array = $notification->toArray($this->admin);
        $this->assertEquals('approval_reminder', $array['type']);
        $this->assertEquals($request->id, $array['request_id']);
    }

    public function test_escalation_notification_to_array(): void
    {
        $request = $this->createRequest();
        $notification = new ApprovalReminderNotification($request, true);

        $array = $notification->toArray($this->admin);
        $this->assertEquals('approval_escalation', $array['type']);
    }
}
