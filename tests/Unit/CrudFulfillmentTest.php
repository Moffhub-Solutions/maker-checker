<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Article;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

/**
 * Tests that CRUD requests are actually fulfilled after approval.
 * This verifies the full flow: create request -> approve -> model created/updated/deleted.
 */
class CrudFulfillmentTest extends BaseTestCase
{
    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config(['maker-checker.delete_on_completion' => false]);

        $this->maker = User::create([
            'name' => 'Maker',
            'email' => 'maker@example.com',
            'role' => 'editor',
        ]);

        $this->checker = User::create([
            'name' => 'Checker',
            'email' => 'checker@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_create_request_fulfills_model_after_approval(): void
    {
        $this->actingAs($this->maker);

        $request = MakerChecker::request()
            ->toCreate(Post::class, ['title' => 'Approved Post', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Post should NOT exist yet
        $this->assertNull(Post::where('title', 'Approved Post')->first());

        // Approve
        MakerChecker::approve($request, $this->checker, 'admin');

        // Post SHOULD exist now (firstOrFail throws if approval did not create it)
        $post = Post::where('title', 'Approved Post')->firstOrFail();
        $this->assertEquals('Approved Post', $post->title);
    }

    public function test_update_request_fulfills_changes_after_approval(): void
    {
        $this->actingAs($this->maker);

        $post = Post::create(['title' => 'Original', 'user_id' => $this->maker->id]);

        $request = MakerChecker::request()
            ->toUpdate($post, ['title' => 'Updated Title'])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Title should still be original
        $this->assertEquals('Original', $post->fresh()->title);

        // Approve
        MakerChecker::approve($request, $this->checker, 'admin');

        // Title should be updated now
        $this->assertEquals('Updated Title', $post->fresh()->title);
    }

    public function test_delete_request_fulfills_deletion_after_approval(): void
    {
        $this->actingAs($this->maker);

        $post = Post::create(['title' => 'To Delete', 'user_id' => $this->maker->id]);
        $postId = $post->id;

        $request = MakerChecker::request()
            ->toDelete($post)
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Post should still exist
        $this->assertNotNull(Post::find($postId));

        // Approve
        MakerChecker::approve($request, $this->checker, 'admin');

        // Post should be deleted now
        $this->assertNull(Post::find($postId));
    }

    /**
     * This test verifies that models using RequiresApproval trait
     * are properly created when their approval request is fulfilled.
     *
     * Bug scenario: fulfillRequest calls firstOrCreate, which triggers
     * the RequiresApproval trait again, preventing the model from being created.
     */
    public function test_create_request_fulfills_model_with_requires_approval_trait(): void
    {
        Article::setApprovalMaker($this->maker);

        $this->actingAs($this->maker);

        // Create a request for an Article (which uses RequiresApproval trait)
        $request = MakerChecker::request()
            ->toCreate(Article::class, ['title' => 'Approved Article', 'user_id' => $this->maker->id])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Article should NOT exist yet
        $this->assertNull(Article::where('title', 'Approved Article')->first());

        // Approve - this should create the Article without re-intercepting
        MakerChecker::approve($request, $this->checker, 'admin');

        // Article SHOULD exist now (firstOrFail throws if the trait blocked it)
        $article = Article::where('title', 'Approved Article')->firstOrFail();
        $this->assertEquals('Approved Article', $article->title);

        Article::setApprovalMaker(null);
    }

    public function test_update_request_fulfills_model_with_requires_approval_trait(): void
    {
        Article::setApprovalMaker($this->maker);

        $this->actingAs($this->maker);

        $article = Article::createWithoutApproval([
            'title' => 'Original Article',
            'user_id' => $this->maker->id,
        ]);

        $request = MakerChecker::request()
            ->toUpdate($article, ['title' => 'Updated Article'])
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Should still be original
        $this->assertEquals('Original Article', $article->fresh()->title);

        // Approve
        MakerChecker::approve($request, $this->checker, 'admin');

        // Should be updated now
        $this->assertEquals('Updated Article', $article->fresh()->title);

        Article::setApprovalMaker(null);
    }

    public function test_delete_request_fulfills_model_with_requires_approval_trait(): void
    {
        Article::setApprovalMaker($this->maker);

        $this->actingAs($this->maker);

        $article = Article::createWithoutApproval([
            'title' => 'To Delete Article',
            'user_id' => $this->maker->id,
        ]);
        $articleId = $article->id;

        $request = MakerChecker::request()
            ->toDelete($article)
            ->withApprovals(['admin' => 1])
            ->madeBy($this->maker)
            ->save();

        // Should still exist
        $this->assertNotNull(Article::find($articleId));

        // Approve
        MakerChecker::approve($request, $this->checker, 'admin');

        // Should be deleted now
        $this->assertNull(Article::find($articleId));

        Article::setApprovalMaker(null);
    }
}
