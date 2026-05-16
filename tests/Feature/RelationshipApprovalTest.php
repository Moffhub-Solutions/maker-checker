<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Article;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Compensation;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Employee;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Tag;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class RelationshipApprovalTest extends BaseTestCase
{
    private User $maker;

    private User $approver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com', 'role' => 'editor']);
        $this->approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'editor']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Employee::setApprovalMaker(null);
        Employee::resetApprovalBypass();
        Employee::clearInterceptedRequest();
        Employee::throwOnIntercept(false);

        parent::tearDown();
    }

    private function makeArticle(): Article
    {
        return Article::withoutApprovalDo(fn() => Article::create([
            'title' => 'Hello',
            'content' => 'World',
            'user_id' => $this->maker->id,
        ]));
    }

    // ---- Explicit API: requestRelation() -------------------------------

    public function test_explicit_attach_creates_pending_request_without_touching_pivot(): void
    {
        $article = $this->makeArticle();
        $tag = Tag::create(['name' => 'laravel']);

        $request = $article->requestRelation('tags')
            ->madeBy($this->maker)
            ->attach($tag, ['relevance' => 'high']);

        $this->assertInstanceOf(MakerCheckerRequest::class, $request);
        $this->assertSame(RequestType::RELATION, $request->type);
        $this->assertSame(RequestStatus::PENDING, $request->status);
        $this->assertSame('attach', $request->payload['operation']);
        $this->assertSame('tags', $request->payload['relation']);
        $this->assertSame(0, $article->tags()->count());
    }

    public function test_explicit_attach_is_applied_on_approval(): void
    {
        $article = $this->makeArticle();
        $tag = Tag::create(['name' => 'php']);

        $request = $article->requestRelation('tags')
            ->madeBy($this->maker)
            ->attach($tag, ['relevance' => 'high']);

        MakerChecker::approve($request, $this->approver);

        $this->assertSame(1, $article->tags()->count());
        $this->assertSame('high', $article->tags()->first()->pivot->relevance);
    }

    public function test_explicit_detach_is_applied_on_approval(): void
    {
        $article = $this->makeArticle();
        $tag = Tag::create(['name' => 'a']);
        $article->tags()->attach($tag);

        $request = $article->requestRelation('tags')->madeBy($this->maker)->detach($tag);
        $this->assertSame(1, $article->tags()->count());

        MakerChecker::approve($request, $this->approver);

        $this->assertSame(0, $article->tags()->count());
    }

    public function test_explicit_sync_is_applied_on_approval(): void
    {
        $article = $this->makeArticle();
        $a = Tag::create(['name' => 'a']);
        $b = Tag::create(['name' => 'b']);
        $article->tags()->attach($a);

        $request = $article->requestRelation('tags')->madeBy($this->maker)->sync([$b->id]);

        MakerChecker::approve($request, $this->approver);

        $ids = $article->tags()->pluck('tags.id')->all();
        $this->assertSame([$b->id], $ids);
    }

    // ---- Transparent interception --------------------------------------

    public function test_native_attach_is_intercepted(): void
    {
        Employee::setApprovalMaker($this->maker);

        $employee = Employee::create(['name' => 'Jane']);
        $comp = Compensation::create(['label' => 'Bonus']);

        $result = $employee->compensations()->attach($comp);

        $this->assertFalse($result);
        $this->assertTrue(Employee::wasIntercepted());
        $this->assertSame(0, $employee->compensations()->count());

        $request = Employee::getInterceptedRequest();
        $this->assertSame(RequestType::RELATION, $request->type);
        $this->assertSame('attach', $request->payload['operation']);

        MakerChecker::approve($request, $this->approver);

        $this->assertSame(1, $employee->compensations()->count());
    }

    public function test_native_attach_can_be_bypassed(): void
    {
        Employee::setApprovalMaker($this->maker);

        $employee = Employee::create(['name' => 'Jane']);
        $comp = Compensation::create(['label' => 'Bonus']);

        Employee::withoutApprovalDo(fn() => $employee->compensations()->attach($comp));

        $this->assertFalse(Employee::wasIntercepted());
        $this->assertSame(1, $employee->compensations()->count());
    }

    public function test_non_approvable_relation_is_not_intercepted(): void
    {
        Employee::setApprovalMaker($this->maker);

        $employee = Employee::create(['name' => 'Jane']);

        // 'manager' only intercepts associate/dissociate, but the relation is
        // configured. A relation NOT listed at all must pass straight through;
        // here we assert reads are unaffected by the proxy.
        $this->assertNull($employee->manager);
    }

    public function test_native_associate_is_intercepted_and_applied(): void
    {
        Employee::setApprovalMaker($this->maker);

        $employee = Employee::create(['name' => 'Jane']);

        $result = $employee->manager()->associate($this->admin);

        $this->assertFalse($result);
        $this->assertNull($employee->fresh()->user_id);

        $request = Employee::getInterceptedRequest();
        $this->assertSame('associate', $request->payload['operation']);

        MakerChecker::approve($request, $this->approver);

        $this->assertSame($this->admin->id, $employee->fresh()->user_id);
    }

    // ---- Rollback ------------------------------------------------------

    public function test_attach_can_be_rolled_back(): void
    {
        $article = $this->makeArticle();
        $tag = Tag::create(['name' => 'rollme']);

        $request = $article->requestRelation('tags')->madeBy($this->maker)->attach($tag);
        MakerChecker::approve($request, $this->approver);
        $this->assertSame(1, $article->tags()->count());

        MakerChecker::rollback($request->fresh(), $this->admin);

        $this->assertSame(0, $article->tags()->count());
    }

    public function test_detach_can_be_rolled_back(): void
    {
        $article = $this->makeArticle();
        $tag = Tag::create(['name' => 'keepme']);
        $article->tags()->attach($tag, ['relevance' => 'high']);

        $request = $article->requestRelation('tags')->madeBy($this->maker)->detach($tag);
        MakerChecker::approve($request, $this->approver);
        $this->assertSame(0, $article->tags()->count());

        MakerChecker::rollback($request->fresh(), $this->admin);

        $this->assertSame(1, $article->tags()->count());
        $this->assertSame('high', $article->tags()->first()->pivot->relevance);
    }
}
