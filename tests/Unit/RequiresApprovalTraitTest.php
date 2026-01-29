<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Exceptions\PendingApprovalException;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Article;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class RequiresApprovalTraitTest extends BaseTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'editor',
        ]);

        // Set the approval maker for tests
        Article::setApprovalMaker($this->user);
        Article::clearInterceptedRequest();
    }

    protected function tearDown(): void
    {
        // Reset state after each test
        Article::setApprovalMaker(null);
        Article::resetApprovalBypass();
        Article::clearInterceptedRequest();
        Article::throwOnIntercept(false);

        parent::tearDown();
    }

    public function test_create_returns_false_when_intercepted(): void
    {
        $article = new Article([
            'title' => 'Test Article',
            'content' => 'Test content',
            'user_id' => $this->user->id,
        ]);

        $result = $article->save();

        $this->assertFalse($result);
        $this->assertTrue(Article::wasIntercepted());
        $this->assertFalse($article->exists);
    }

    public function test_create_stores_intercepted_request(): void
    {
        $article = new Article([
            'title' => 'Test Article',
            'content' => 'Test content',
            'user_id' => $this->user->id,
        ]);

        $article->save();

        $request = Article::getInterceptedRequest();

        $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        $this->assertEquals(RequestType::CREATE, $request->type);
        $this->assertEquals(RequestStatus::PENDING, $request->status);
        $this->assertEquals(Article::class, $request->subject_type);
        $this->assertEquals('Test Article', $request->payload['title']);
        $this->assertEquals($this->user->id, $request->maker_id);
    }

    public function test_create_with_approval_requirements(): void
    {
        $article = new Article([
            'title' => 'Test Article',
            'user_id' => $this->user->id,
        ]);

        $article->save();

        $request = Article::getInterceptedRequest();

        // Article has 'create' => ['editor' => 1] requirement
        $this->assertEquals(['editor' => 1], $request->required_approvals);
    }

    public function test_update_returns_false_when_intercepted(): void
    {
        // First create without approval
        $article = Article::createWithoutApproval([
            'title' => 'Original Title',
            'content' => 'Original content',
            'user_id' => $this->user->id,
        ]);

        $article->title = 'Updated Title';
        $result = $article->save();

        $this->assertFalse($result);
        $this->assertTrue(Article::wasIntercepted());
    }

    public function test_update_creates_maker_checker_request(): void
    {
        $article = Article::createWithoutApproval([
            'title' => 'Original Title',
            'user_id' => $this->user->id,
        ]);

        $article->title = 'Updated Title';
        $article->save();

        $request = Article::getInterceptedRequest();

        $this->assertEquals(RequestType::UPDATE, $request->type);
        $this->assertEquals($article->id, $request->subject_id);
        $this->assertEquals(['title' => 'Updated Title'], $request->payload);
    }

    public function test_delete_returns_false_when_intercepted(): void
    {
        $article = Article::createWithoutApproval([
            'title' => 'Test Article',
            'user_id' => $this->user->id,
        ]);

        $result = $article->delete();

        $this->assertFalse($result);
        $this->assertTrue(Article::wasIntercepted());
    }

    public function test_delete_creates_maker_checker_request(): void
    {
        $article = Article::createWithoutApproval([
            'title' => 'Test Article',
            'user_id' => $this->user->id,
        ]);

        $article->delete();

        $request = Article::getInterceptedRequest();

        $this->assertEquals(RequestType::DELETE, $request->type);
        $this->assertEquals($article->id, $request->subject_id);
        // Delete has admin => 1 requirement
        $this->assertEquals(['admin' => 1], $request->required_approvals);
    }

    public function test_throw_on_intercept_throws_exception(): void
    {
        Article::throwOnIntercept(true);

        $this->expectException(PendingApprovalException::class);
        $this->expectExceptionMessage('Create operation requires approval.');

        Article::create([
            'title' => 'Test Article',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_create_without_approval_bypasses_workflow(): void
    {
        $article = Article::createWithoutApproval([
            'title' => 'Direct Create',
            'user_id' => $this->user->id,
        ]);

        $this->assertInstanceOf(Article::class, $article);
        $this->assertTrue($article->exists);
        $this->assertEquals('Direct Create', $article->title);
        $this->assertFalse(Article::wasIntercepted());
    }

    public function test_update_without_approval_bypasses_workflow(): void
    {
        $article = Article::createWithoutApproval([
            'title' => 'Original',
            'user_id' => $this->user->id,
        ]);

        Article::clearInterceptedRequest();
        $result = $article->updateWithoutApproval(['title' => 'Updated']);

        $this->assertTrue($result);
        $this->assertEquals('Updated', $article->fresh()->title);
        $this->assertFalse(Article::wasIntercepted());
    }

    public function test_delete_without_approval_bypasses_workflow(): void
    {
        $article = Article::createWithoutApproval([
            'title' => 'To Delete',
            'user_id' => $this->user->id,
        ]);

        $id = $article->id;
        Article::clearInterceptedRequest();
        $result = $article->deleteWithoutApproval();

        $this->assertTrue($result);
        $this->assertNull(Article::find($id));
        $this->assertFalse(Article::wasIntercepted());
    }

    public function test_without_approval_static_method(): void
    {
        Article::withoutApproval();

        $article = Article::create([
            'title' => 'Static Bypass',
            'user_id' => $this->user->id,
        ]);

        $this->assertInstanceOf(Article::class, $article);
        $this->assertTrue($article->exists);
    }

    public function test_without_approval_do_callback(): void
    {
        $article = Article::withoutApprovalDo(function () {
            return Article::create([
                'title' => 'Callback Create',
                'user_id' => $this->user->id,
            ]);
        });

        $this->assertInstanceOf(Article::class, $article);
        $this->assertTrue($article->exists);
        $this->assertEquals('Callback Create', $article->title);
    }

    public function test_bypass_flag_resets_after_operation(): void
    {
        Article::withoutApproval();
        Article::create([
            'title' => 'First',
            'user_id' => $this->user->id,
        ]);

        // Second create should require approval again
        $article = new Article([
            'title' => 'Second',
            'user_id' => $this->user->id,
        ]);
        $result = $article->save();

        $this->assertFalse($result);
        $this->assertTrue(Article::wasIntercepted());
    }

    public function test_no_interception_when_no_maker_available(): void
    {
        Article::setApprovalMaker(null);

        // Log out to ensure no authenticated user
        $this->app['auth']->guard()->logout();

        // Should not intercept when no maker is available
        $article = Article::create([
            'title' => 'No Maker',
            'user_id' => $this->user->id,
        ]);

        $this->assertInstanceOf(Article::class, $article);
        $this->assertTrue($article->exists);
        $this->assertFalse(Article::wasIntercepted());
    }

    public function test_intercepted_request_contains_details(): void
    {
        $article = new Article([
            'title' => 'Test',
            'user_id' => $this->user->id,
        ]);
        $article->save();

        $request = Article::getInterceptedRequest();

        $this->assertNotEmpty($request->code);
        $this->assertNotNull($request->id);
    }

    public function test_has_pending_approval(): void
    {
        $article = Article::createWithoutApproval([
            'title' => 'Test',
            'user_id' => $this->user->id,
        ]);

        $this->assertFalse($article->hasPendingApproval());

        // Try to update (creates pending request)
        $article->title = 'Updated';
        $article->save();

        $this->assertTrue($article->hasPendingApproval());
        $this->assertTrue($article->hasPendingApproval(RequestType::UPDATE));
        $this->assertFalse($article->hasPendingApproval(RequestType::DELETE));
    }

    public function test_get_pending_approvals(): void
    {
        $article = Article::createWithoutApproval([
            'title' => 'Test',
            'user_id' => $this->user->id,
        ]);

        $this->assertCount(0, $article->getPendingApprovals());

        // Try to update
        $article->title = 'Updated';
        $article->save();

        $pending = $article->getPendingApprovals();
        $this->assertCount(1, $pending);
        $this->assertEquals(RequestType::UPDATE, $pending->first()->type);
    }

    public function test_generates_description_automatically(): void
    {
        $article = new Article([
            'title' => 'Test',
            'user_id' => $this->user->id,
        ]);
        $article->save();

        $request = Article::getInterceptedRequest();

        $this->assertEquals('Create new Article', $request->description);
    }

    public function test_clear_intercepted_request(): void
    {
        $article = new Article([
            'title' => 'Test',
            'user_id' => $this->user->id,
        ]);
        $article->save();

        $this->assertTrue(Article::wasIntercepted());

        Article::clearInterceptedRequest();

        $this->assertFalse(Article::wasIntercepted());
        $this->assertNull(Article::getInterceptedRequest());
    }

    public function test_was_intercepted_returns_false_initially(): void
    {
        Article::clearInterceptedRequest();

        $this->assertFalse(Article::wasIntercepted());
    }
}
