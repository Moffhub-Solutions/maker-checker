<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Events\RequestRolledBack;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeRolledBack;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class RollbackTest extends BaseTestCase
{
    public function test_can_rollback_approved_create_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Post', 'content' => 'Test content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        // Approve the request (which creates the post)
        Event::fake(); // Reset events
        MakerChecker::approve($request, $admin, 'admin');

        // Verify post was created
        $this->assertDatabaseHas('posts', ['title' => 'Test Post']);

        // Rollback the request
        $rolledBackRequest = MakerChecker::rollback($request->fresh(), $admin, 'Rolling back');

        $this->assertEquals(RequestStatus::ROLLED_BACK, $rolledBackRequest->status);
        $this->assertEquals('Rolling back', $rolledBackRequest->remarks);
        $this->assertDatabaseMissing('posts', ['title' => 'Test Post']);

        Event::assertDispatched(RequestRolledBack::class);
    }

    public function test_can_rollback_approved_update_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original content',
            'user_id' => $maker->id,
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toUpdate($post, ['title' => 'Updated Title'])
            ->withApprovals(['admin' => 1])
            ->save();

        // Verify original values are stored in metadata
        $this->assertArrayHasKey('original_values', $request->metadata);
        $this->assertEquals(['title' => 'Original Title'], $request->metadata['original_values']);

        // Approve the request (which updates the post)
        Event::fake();
        MakerChecker::approve($request, $admin, 'admin');

        $post->refresh();
        $this->assertEquals('Updated Title', $post->title);

        // Rollback the request
        $rolledBackRequest = MakerChecker::rollback($request->fresh(), $admin);

        $this->assertEquals(RequestStatus::ROLLED_BACK, $rolledBackRequest->status);

        $post->refresh();
        $this->assertEquals('Original Title', $post->title);

        Event::assertDispatched(RequestRolledBack::class);
    }

    public function test_cannot_rollback_delete_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $post = Post::create([
            'title' => 'To Delete',
            'content' => 'Content',
            'user_id' => $maker->id,
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toDelete($post)
            ->withApprovals(['admin' => 1])
            ->save();

        // Approve the request (which deletes the post)
        Event::fake();
        MakerChecker::approve($request, $admin, 'admin');

        $this->expectException(RequestCannotBeRolledBack::class);
        $this->expectExceptionMessage('Delete requests cannot be rolled back.');

        MakerChecker::rollback($request->fresh(), $admin);
    }

    public function test_cannot_rollback_pending_request(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->save();

        $this->expectException(RequestCannotBeRolledBack::class);
        $this->expectExceptionMessage('Only approved requests can be rolled back.');

        MakerChecker::rollback($request, $admin);
    }

    public function test_non_admin_cannot_rollback(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $regularUser = User::create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'role' => 'user',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        Event::fake();
        MakerChecker::approve($request, $admin, 'admin');

        $this->expectException(RequestCannotBeRolledBack::class);
        $this->expectExceptionMessage('You are not authorized to rollback this request.');

        MakerChecker::rollback($request->fresh(), $regularUser);
    }

    public function test_whitelisted_email_can_rollback(): void
    {
        $this->app['config']->set('maker-checker.whitelisted_emails', 'super@example.com');

        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $superUser = User::create([
            'name' => 'Super User',
            'email' => 'super@example.com',
            'role' => 'user', // Not admin, but whitelisted
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test Rollback', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        Event::fake();
        MakerChecker::approve($request, $admin, 'admin');

        $this->assertDatabaseHas('posts', ['title' => 'Test Rollback']);

        $rolledBackRequest = MakerChecker::rollback($request->fresh(), $superUser, 'Whitelisted rollback');

        $this->assertEquals(RequestStatus::ROLLED_BACK, $rolledBackRequest->status);
        $this->assertDatabaseMissing('posts', ['title' => 'Test Rollback']);
    }

    public function test_rollback_via_model_convenience_method(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Convenience Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        Event::fake();
        MakerChecker::approve($request, $admin, 'admin');

        $freshRequest = $request->fresh();
        $freshRequest->rollback($admin);

        $this->assertDatabaseMissing('posts', ['title' => 'Convenience Test']);
    }

    public function test_is_rolled_back_status_check(): void
    {
        $request = new MakerCheckerRequest;
        $request->status = RequestStatus::ROLLED_BACK;

        $this->assertTrue($request->isRolledBack());
        $this->assertFalse($request->isApproved());
        $this->assertTrue($request->isFinalized());
    }

    public function test_rolled_back_is_finalized_status(): void
    {
        $finalizedStatuses = RequestStatus::getFinalizedStatuses();

        $this->assertContains(RequestStatus::ROLLED_BACK, $finalizedStatuses);
    }

    public function test_update_rollback_without_original_values_throws(): void
    {
        Event::fake();

        $maker = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $post = Post::create([
            'title' => 'Original',
            'content' => 'Content',
            'user_id' => $maker->id,
        ]);

        // Manually create a request without original_values in metadata
        $request = new MakerCheckerRequest;
        $request->code = (string) \Illuminate\Support\Str::uuid();
        $request->description = 'Test update';
        $request->type = RequestType::UPDATE;
        $request->status = RequestStatus::APPROVED;
        $request->subject_type = Post::class;
        $request->subject_id = $post->id;
        $request->maker_type = User::class;
        $request->maker_id = $maker->id;
        $request->made_at = now();
        $request->payload = ['title' => 'New Title'];
        $request->metadata = ['hooks' => []];
        $request->saveOrFail();

        $this->expectException(RequestCannotBeRolledBack::class);
        $this->expectExceptionMessage('Original values were not captured');

        MakerChecker::rollback($request, $admin);
    }

    public function test_rolled_back_display_name(): void
    {
        $this->assertEquals('Rolled Back', RequestStatus::ROLLED_BACK->display());
    }
}
