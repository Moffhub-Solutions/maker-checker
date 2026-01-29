<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerConfig;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Comment;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

/**
 * Tests for RequiresApproval trait integration with ConfigResolver.
 * This tests that the trait respects database-driven configuration.
 */
class RequiresApprovalConfigResolverTest extends BaseTestCase
{
    private User $user;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'editor',
        ]);

        $this->post = Post::create([
            'title' => 'Test Post',
            'content' => 'Test content',
            'user_id' => $this->user->id,
        ]);

        Comment::setApprovalMaker($this->user);
        Comment::clearInterceptedRequest();
    }

    protected function tearDown(): void
    {
        Comment::setApprovalMaker(null);
        Comment::resetApprovalBypass();
        Comment::clearInterceptedRequest();
        Comment::throwOnIntercept(false);

        parent::tearDown();
    }

    public function test_uses_file_config_approvals_when_no_model_property(): void
    {
        // Configure global approvals in file config
        config(['maker-checker.global_approvals' => [
            'create' => ['reviewer' => 2],
        ]]);

        $comment = new Comment([
            'body' => 'Test comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        $result = $comment->save();

        $this->assertFalse($result);
        $this->assertTrue(Comment::wasIntercepted());

        $request = Comment::getInterceptedRequest();
        $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        $this->assertEquals(['reviewer' => 2], $request->required_approvals);
    }

    public function test_uses_database_config_when_driver_is_database(): void
    {
        // Set config driver to database
        config(['maker-checker.config_driver' => 'database']);

        // Create database config for Comment model
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => RequestType::CREATE,
            'approvals' => ['manager' => 1, 'director' => 1],
            'is_active' => true,
        ]);

        $comment = new Comment([
            'body' => 'Test comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        $result = $comment->save();

        $this->assertFalse($result);
        $this->assertTrue(Comment::wasIntercepted());

        $request = Comment::getInterceptedRequest();
        $this->assertEquals(['manager' => 1, 'director' => 1], $request->required_approvals);
    }

    public function test_uses_model_specific_file_config(): void
    {
        // Configure model-specific approvals in file config
        config(['maker-checker.models' => [
            Comment::class => [
                'approvals' => [
                    'create' => ['admin' => 1],
                    'update' => ['editor' => 1],
                ],
            ],
        ]]);

        $comment = new Comment([
            'body' => 'Test comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        $result = $comment->save();

        $this->assertFalse($result);

        $request = Comment::getInterceptedRequest();
        $this->assertEquals(['admin' => 1], $request->required_approvals);
    }

    public function test_database_config_takes_precedence_over_file_config(): void
    {
        // Set config driver to database
        config(['maker-checker.config_driver' => 'database']);

        // Set file config (lower priority)
        config(['maker-checker.global_approvals' => [
            'create' => ['file_role' => 5],
        ]]);

        // Create database config (higher priority)
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => RequestType::CREATE,
            'approvals' => ['database_role' => 3],
            'is_active' => true,
        ]);

        $comment = new Comment([
            'body' => 'Test comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        $comment->save();

        $request = Comment::getInterceptedRequest();
        // Database config should take precedence
        $this->assertEquals(['database_role' => 3], $request->required_approvals);
    }

    public function test_team_scoped_database_config(): void
    {
        // Set config driver to database
        config(['maker-checker.config_driver' => 'database']);
        config(['maker-checker.team_scope.enabled' => true]);

        // Create team-scoped database config
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => RequestType::CREATE,
            'approvals' => ['team_lead' => 1],
            'team_id' => 1,
            'is_active' => true,
        ]);

        // Create global database config (fallback)
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => RequestType::CREATE,
            'approvals' => ['global_role' => 2],
            'team_id' => null,
            'is_active' => true,
        ]);

        $comment = new Comment([
            'body' => 'Test comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        $comment->save();

        $request = Comment::getInterceptedRequest();
        // Should use global config since no team context
        $this->assertEquals(['global_role' => 2], $request->required_approvals);
    }

    public function test_inactive_database_config_is_ignored(): void
    {
        // Set config driver to database
        config(['maker-checker.config_driver' => 'database']);

        // Create inactive database config
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => RequestType::CREATE,
            'approvals' => ['inactive_role' => 10],
            'is_active' => false,
        ]);

        // Set file config as fallback
        config(['maker-checker.global_approvals' => [
            'create' => ['fallback_role' => 1],
        ]]);

        $comment = new Comment([
            'body' => 'Test comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        $comment->save();

        $request = Comment::getInterceptedRequest();
        // Should fall back to file config since database config is inactive
        $this->assertEquals(['fallback_role' => 1], $request->required_approvals);
    }

    public function test_uses_default_description_from_config_resolver(): void
    {
        $comment = new Comment([
            'body' => 'Test comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        $comment->save();

        $request = Comment::getInterceptedRequest();
        $this->assertEquals('Create new Comment', $request->description);
    }

    public function test_update_uses_database_config(): void
    {
        // Set config driver to database
        config(['maker-checker.config_driver' => 'database']);

        // Create database config for update action
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => RequestType::UPDATE,
            'approvals' => ['supervisor' => 1],
            'is_active' => true,
        ]);

        // Create comment without approval
        $comment = Comment::createWithoutApproval([
            'body' => 'Original comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        Comment::clearInterceptedRequest();

        // Try to update
        $comment->body = 'Updated comment';
        $result = $comment->save();

        $this->assertFalse($result);
        $this->assertTrue(Comment::wasIntercepted());

        $request = Comment::getInterceptedRequest();
        $this->assertEquals(RequestType::UPDATE, $request->type);
        $this->assertEquals(['supervisor' => 1], $request->required_approvals);
    }

    public function test_delete_uses_database_config(): void
    {
        // Set config driver to database
        config(['maker-checker.config_driver' => 'database']);

        // Create database config for delete action
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => RequestType::DELETE,
            'approvals' => ['admin' => 2],
            'is_active' => true,
        ]);

        // Create comment without approval
        $comment = Comment::createWithoutApproval([
            'body' => 'Comment to delete',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        Comment::clearInterceptedRequest();

        // Try to delete
        $result = $comment->delete();

        $this->assertFalse($result);
        $this->assertTrue(Comment::wasIntercepted());

        $request = Comment::getInterceptedRequest();
        $this->assertEquals(RequestType::DELETE, $request->type);
        $this->assertEquals(['admin' => 2], $request->required_approvals);
    }

    public function test_respects_required_for_from_file_config(): void
    {
        // Configure model to only require approval for delete
        config(['maker-checker.models' => [
            Comment::class => [
                'required_for' => ['delete'],
                'approvals' => [
                    'delete' => ['admin' => 1],
                ],
            ],
        ]]);

        // Create should proceed without interception
        $comment = new Comment([
            'body' => 'Test comment',
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);

        $result = $comment->save();

        // Should not be intercepted since create is not in required_for
        $this->assertTrue($result);
        $this->assertFalse(Comment::wasIntercepted());
        $this->assertTrue($comment->exists);
    }
}
