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
use Moffhub\MakerChecker\Tests\Fixtures\Models\Note;
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
        $this->assertSame(1, $article->tags()->wherePivot('relevance', 'high')->count());
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

        // attach() is void in Eloquent, so interception is detected via
        // wasIntercepted() / getInterceptedRequest(), not a return value.
        $employee->compensations()->attach($comp);

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
        $this->assertSame(1, $article->tags()->wherePivot('relevance', 'high')->count());
    }

    // ---- Remaining operations ------------------------------------------

    public function test_explicit_sync_without_detaching_is_applied_on_approval(): void
    {
        $article = $this->makeArticle();
        $a = Tag::create(['name' => 'a']);
        $b = Tag::create(['name' => 'b']);
        $article->tags()->attach($a);

        $request = $article->requestRelation('tags')->madeBy($this->maker)->syncWithoutDetaching([$b->id]);
        $this->assertSame(1, $article->tags()->count());

        MakerChecker::approve($request, $this->approver);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $article->tags()->pluck('tags.id')->all());
    }

    public function test_explicit_toggle_is_applied_on_approval(): void
    {
        $article = $this->makeArticle();
        $a = Tag::create(['name' => 'a']);
        $b = Tag::create(['name' => 'b']);
        $article->tags()->attach($a);

        $request = $article->requestRelation('tags')->madeBy($this->maker)->toggle([$a->id, $b->id]);
        $this->assertSame([$a->id], $article->tags()->pluck('tags.id')->all());

        MakerChecker::approve($request, $this->approver);

        $this->assertSame([$b->id], $article->tags()->pluck('tags.id')->all());
    }

    public function test_explicit_update_existing_pivot_is_applied_on_approval(): void
    {
        $article = $this->makeArticle();
        $tag = Tag::create(['name' => 'a']);
        $article->tags()->attach($tag, ['relevance' => 'low']);

        $request = $article->requestRelation('tags')
            ->madeBy($this->maker)
            ->updateExistingPivot($tag->id, ['relevance' => 'high']);
        $this->assertSame(1, $article->tags()->wherePivot('relevance', 'low')->count());

        MakerChecker::approve($request, $this->approver);

        $this->assertSame(1, $article->tags()->wherePivot('relevance', 'high')->count());
    }

    public function test_explicit_associate_and_dissociate_are_applied_on_approval(): void
    {
        $employee = Employee::withoutApprovalDo(fn() => Employee::create(['name' => 'Jane']));

        $associate = $employee->requestRelation('manager')->madeBy($this->maker)->associate($this->admin);
        $this->assertNull($employee->fresh()->user_id);

        MakerChecker::approve($associate, $this->approver);
        $this->assertSame($this->admin->id, $employee->fresh()->user_id);

        $dissociate = $employee->fresh()->requestRelation('manager')->madeBy($this->maker)->dissociate();
        $this->assertSame($this->admin->id, $employee->fresh()->user_id);

        MakerChecker::approve($dissociate, $this->approver);
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_sync_can_be_rolled_back(): void
    {
        $article = $this->makeArticle();
        $a = Tag::create(['name' => 'a']);
        $b = Tag::create(['name' => 'b']);
        $article->tags()->attach($a);

        $request = $article->requestRelation('tags')->madeBy($this->maker)->sync([$b->id]);
        MakerChecker::approve($request, $this->approver);
        $this->assertSame([$b->id], $article->tags()->pluck('tags.id')->all());

        MakerChecker::rollback($request->fresh(), $this->admin);

        $this->assertSame([$a->id], $article->tags()->pluck('tags.id')->all());
    }

    public function test_associate_can_be_rolled_back(): void
    {
        $employee = Employee::withoutApprovalDo(fn() => Employee::create(['name' => 'Jane']));

        $request = $employee->requestRelation('manager')->madeBy($this->maker)->associate($this->admin);
        MakerChecker::approve($request, $this->approver);
        $this->assertSame($this->admin->id, $employee->fresh()->user_id);

        MakerChecker::rollback($request->fresh(), $this->admin);

        $this->assertNull($employee->fresh()->user_id);
    }

    // ---- HasOne / HasMany / MorphMany child writes ----------------------

    private function makeEmployee(): Employee
    {
        return Employee::withoutApprovalDo(fn() => Employee::create(['name' => 'Jane']));
    }

    public function test_native_has_many_create_is_intercepted_and_applied(): void
    {
        $employee = $this->makeEmployee();
        Employee::setApprovalMaker($this->maker);

        $employee->notes()->create(['body' => 'first']);

        $this->assertSame(0, Note::count());
        $request = Employee::getInterceptedRequest();
        $this->assertSame('create', $request->payload['operation']);

        MakerChecker::approve($request, $this->approver);

        $this->assertSame(1, $employee->notes()->count());
        $this->assertSame(1, Note::where('body', 'first')->count());
    }

    public function test_native_has_many_create_many_is_applied_in_full(): void
    {
        $employee = $this->makeEmployee();
        Employee::setApprovalMaker($this->maker);

        $employee->notes()->createMany([['body' => 'a'], ['body' => 'b'], ['body' => 'c']]);

        $this->assertSame(0, Note::count());
        MakerChecker::approve(Employee::getInterceptedRequest(), $this->approver);

        $this->assertSame(['a', 'b', 'c'], $employee->notes()->orderBy('id')->pluck('body')->all());
    }

    public function test_native_has_many_save_many_with_new_and_existing_children(): void
    {
        $employee = $this->makeEmployee();
        $existing = Note::create(['body' => 'old']);
        Employee::setApprovalMaker($this->maker);

        $existing->body = 'edited';
        $employee->notes()->saveMany([$existing, new Note(['body' => 'new'])]);

        $this->assertNull($existing->fresh()->employee_id);
        $this->assertSame(1, Note::count());

        MakerChecker::approve(Employee::getInterceptedRequest(), $this->approver);

        $this->assertSame(2, $employee->notes()->count());
        $this->assertSame('edited', $existing->fresh()->body);
        $this->assertSame($employee->id, $existing->fresh()->employee_id);
    }

    public function test_native_has_one_save_is_intercepted(): void
    {
        $employee = $this->makeEmployee();
        Employee::setApprovalMaker($this->maker);

        $employee->latestNote()->save(new Note(['body' => 'only']));

        $this->assertSame(0, Note::count());
        MakerChecker::approve(Employee::getInterceptedRequest(), $this->approver);

        $this->assertSame(1, Note::where('body', 'only')->where('employee_id', $employee->id)->count());
    }

    public function test_morph_many_create_is_intercepted_and_sets_morph_type(): void
    {
        $employee = $this->makeEmployee();
        Employee::setApprovalMaker($this->maker);

        $employee->remarks()->create(['body' => 'remark']);
        $this->assertSame(0, Note::count());

        MakerChecker::approve(Employee::getInterceptedRequest(), $this->approver);

        $note = Note::first();
        $this->assertSame($employee->getMorphClass(), $note->noteable_type);
        $this->assertSame($employee->id, $note->noteable_id);
    }

    public function test_child_operations_not_listed_for_relation_pass_through(): void
    {
        $employee = $this->makeEmployee();
        Employee::setApprovalMaker($this->maker);

        // 'remarks' only gates create(); save() runs immediately.
        $employee->remarks()->save(new Note(['body' => 'direct']));

        $this->assertSame(1, Note::count());
    }

    public function test_child_writes_can_be_bypassed(): void
    {
        $employee = $this->makeEmployee();
        Employee::setApprovalMaker($this->maker);

        Employee::withoutApprovalDo(fn() => $employee->notes()->create(['body' => 'direct']));

        $this->assertSame(1, Note::count());
    }

    public function test_explicit_child_request_is_applied_and_created_children_roll_back(): void
    {
        $employee = $this->makeEmployee();

        $request = $employee->requestRelation('notes')
            ->madeBy($this->maker)
            ->createMany([['body' => 'x'], ['body' => 'y']]);
        $this->assertSame(0, Note::count());

        MakerChecker::approve($request, $this->approver);
        $this->assertSame(2, Note::count());

        MakerChecker::rollback($request->fresh(), $this->admin);

        $this->assertSame(0, Note::count());
    }

    public function test_save_of_existing_child_can_be_rolled_back(): void
    {
        $employee = $this->makeEmployee();
        $note = Note::create(['body' => 'before']);
        $note->body = 'after';

        $request = $employee->requestRelation('notes')->madeBy($this->maker)->save($note);
        MakerChecker::approve($request, $this->approver);

        $this->assertSame('after', $note->fresh()->body);
        $this->assertSame($employee->id, $note->fresh()->employee_id);

        MakerChecker::rollback($request->fresh(), $this->admin);

        $this->assertSame('before', $note->fresh()->body);
        $this->assertNull($note->fresh()->employee_id);
    }

    public function test_native_sync_is_applied_without_intercepting_its_inner_calls(): void
    {
        $employee = $this->makeEmployee();
        $a = Compensation::create(['label' => 'A']);
        $b = Compensation::create(['label' => 'B']);
        Employee::withoutApprovalDo(fn() => $employee->compensations()->attach($a));
        Employee::setApprovalMaker($this->maker);

        $employee->compensations()->sync([$b->id]);
        $request = Employee::getInterceptedRequest();
        $this->assertSame(1, MakerCheckerRequest::count());

        MakerChecker::approve($request, $this->approver);

        $this->assertSame([$b->id], $employee->compensations()->pluck('compensations.id')->all());
    }

    // ---- MorphToMany ---------------------------------------------------

    public function test_native_morph_to_many_attach_is_intercepted_and_applied(): void
    {
        $employee = $this->makeEmployee();
        $tag = Tag::create(['name' => 'vip']);
        Employee::setApprovalMaker($this->maker);

        $employee->labels()->attach($tag, ['relevance' => 'high']);

        $this->assertSame(0, $employee->labels()->count());
        MakerChecker::approve(Employee::getInterceptedRequest(), $this->approver);

        $this->assertSame(1, $employee->labels()->wherePivot('relevance', 'high')->count());
    }

    public function test_morph_to_many_rollback_ignores_rows_of_other_morph_types(): void
    {
        $employee = $this->makeEmployee();
        $article = $this->makeArticle();
        $this->assertSame($employee->id, $article->id);

        $mine = Tag::create(['name' => 'mine']);
        $theirs = Tag::create(['name' => 'theirs']);
        $new = Tag::create(['name' => 'new']);
        Employee::withoutApprovalDo(fn() => $employee->labels()->attach($mine));
        $article->morphToMany(Tag::class, 'taggable')->attach($theirs);

        $request = $employee->requestRelation('labels')->madeBy($this->maker)->sync([$new->id]);
        MakerChecker::approve($request, $this->approver);
        $this->assertSame([$new->id], $employee->labels()->pluck('tags.id')->all());

        MakerChecker::rollback($request->fresh(), $this->admin);

        $this->assertSame([$mine->id], $employee->labels()->pluck('tags.id')->all());
        $this->assertSame(1, $article->morphToMany(Tag::class, 'taggable')->count());
    }
}
