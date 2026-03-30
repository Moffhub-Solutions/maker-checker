<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Exceptions\FulfillmentException;
use Moffhub\MakerChecker\Exceptions\ModelCannotCheckRequests;
use Moffhub\MakerChecker\Exceptions\ModelCannotMakeRequests;
use Moffhub\MakerChecker\Exceptions\RequestCannotBeChecked;
use Moffhub\MakerChecker\Exceptions\RequestCouldNotBeProcessed;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\MakerCheckerRequestManager;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Services\AuditService;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;
use Moffhub\MakerChecker\Tests\Fixtures\TestExecutable;

class ExpandedCoverageTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestExecutable::reset();
    }

    // =========================================================================
    // Soft Delete on Completion
    // =========================================================================

    public function test_soft_delete_on_completion_when_configured(): void
    {
        $this->app['config']->set('maker-checker.delete_on_completion', true);
        $this->app['config']->set('maker-checker.soft_delete_on_completion', true);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Soft Delete Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        $requestId = $request->id;

        MakerChecker::approve($request, $approver, 'admin');

        // The request model doesn't use SoftDeletes trait by default,
        // so it will be force-deleted when soft_delete_on_completion is true
        // but the model doesn't support soft deletes
        $this->assertNull(MakerCheckerRequest::find($requestId));
    }

    public function test_request_not_deleted_when_delete_on_completion_is_false(): void
    {
        $this->app['config']->set('maker-checker.delete_on_completion', false);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Keep Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        $requestId = $request->id;

        MakerChecker::approve($request, $approver, 'admin');

        // Request should still exist
        $this->assertNotNull(MakerCheckerRequest::find($requestId));
    }

    public function test_force_delete_on_completion_when_soft_delete_disabled(): void
    {
        $this->app['config']->set('maker-checker.delete_on_completion', true);
        $this->app['config']->set('maker-checker.soft_delete_on_completion', false);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Force Delete Test', 'content' => 'Content', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        $requestId = $request->id;

        MakerChecker::approve($request, $approver, 'admin');

        $this->assertNull(MakerCheckerRequest::find($requestId));
    }

    // =========================================================================
    // Multi-tenant Filtering (Team Scoping)
    // =========================================================================

    public function test_requests_scoped_to_team(): void
    {
        Event::fake();

        $team1User = User::create(['name' => 'Team 1', 'email' => 'team1@example.com', 'team_id' => 1]);
        $team2User = User::create(['name' => 'Team 2', 'email' => 'team2@example.com', 'team_id' => 2]);

        $request1 = MakerChecker::request()
            ->madeBy($team1User)
            ->toCreate(Post::class, ['title' => 'Team 1 Post', 'content' => 'C', 'user_id' => $team1User->id], [], 1)
            ->save();

        $request2 = MakerChecker::request()
            ->madeBy($team2User)
            ->toCreate(Post::class, ['title' => 'Team 2 Post', 'content' => 'C', 'user_id' => $team2User->id], [], 2)
            ->save();

        // Team scoping via scope
        $team1Requests = MakerCheckerRequest::forTeam(1)->get();
        $team2Requests = MakerCheckerRequest::forTeam(2)->get();

        $this->assertCount(1, $team1Requests);
        $this->assertCount(1, $team2Requests);
        $this->assertEquals('Team 1 Post', $team1Requests->first()->payload['title']);
        $this->assertEquals('Team 2 Post', $team2Requests->first()->payload['title']);
    }

    public function test_visible_to_scope_returns_team_requests(): void
    {
        Event::fake();

        $teamUser = User::create(['name' => 'Team User', 'email' => 'teamuser@example.com', 'team_id' => 1]);
        $otherTeamUser = User::create(['name' => 'Other Team', 'email' => 'other@example.com', 'team_id' => 1]);

        MakerChecker::request()
            ->madeBy($teamUser)
            ->toCreate(Post::class, ['title' => 'T1', 'content' => 'C', 'user_id' => $teamUser->id], [], 1)
            ->save();

        MakerChecker::request()
            ->madeBy($otherTeamUser)
            ->toCreate(Post::class, ['title' => 'T2', 'content' => 'C', 'user_id' => $otherTeamUser->id], [], 1)
            ->save();

        // The other team user should see both requests via team scoping
        $visible = MakerCheckerRequest::visibleTo($otherTeamUser)->get();
        $this->assertCount(2, $visible);
    }

    // =========================================================================
    // Model Whitelisting
    // =========================================================================

    public function test_whitelisted_checker_model_can_approve(): void
    {
        $this->app['config']->set('maker-checker.whitelisted_models.checker', [User::class]);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Whitelist Test', 'content' => 'C', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        $result = MakerChecker::approve($request, $approver, 'admin');
        $this->assertEquals(RequestStatus::APPROVED, $result->status);
    }

    public function test_non_whitelisted_checker_model_cannot_approve(): void
    {
        $this->app['config']->set('maker-checker.whitelisted_models.checker', ['App\\Models\\Admin']);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Block Test', 'content' => 'C', 'user_id' => $maker->id])
            ->save();

        $this->expectException(ModelCannotCheckRequests::class);
        MakerChecker::approve($request, $approver);
    }

    public function test_whitelisted_maker_model_can_create_request(): void
    {
        Event::fake();
        $this->app['config']->set('maker-checker.whitelisted_models.maker', [User::class]);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Maker Test', 'content' => 'C', 'user_id' => $maker->id])
            ->save();

        $this->assertNotNull($request);
    }

    public function test_non_whitelisted_maker_model_cannot_create_request(): void
    {
        Event::fake();
        $this->app['config']->set('maker-checker.whitelisted_models.maker', ['App\\Models\\Admin']);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);

        $this->expectException(ModelCannotMakeRequests::class);

        MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Block Test', 'content' => 'C', 'user_id' => $maker->id])
            ->save();
    }

    // =========================================================================
    // Expired Request Handling
    // =========================================================================

    public function test_expired_request_cannot_be_approved(): void
    {
        $this->app['config']->set('maker-checker.request_expiration_in_minutes', 60);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Expired Test', 'content' => 'C', 'user_id' => $maker->id])
            ->save();

        // Travel forward in time past the expiration window
        $this->travel(2)->hours();

        // Create a fresh manager to pick up the config
        $manager = new MakerCheckerRequestManager($this->app);

        $this->expectException(RequestCannotBeChecked::class);
        $this->expectExceptionMessage('Expired request');
        $manager->approve($request, $approver);
    }

    public function test_expired_request_cannot_be_rejected(): void
    {
        $this->app['config']->set('maker-checker.request_expiration_in_minutes', 30);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $rejector = User::create(['name' => 'Rejector', 'email' => 'rejector@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Expired Test', 'content' => 'C', 'user_id' => $maker->id])
            ->save();

        // Travel forward in time past the expiration window
        $this->travel(1)->hours();

        // Create a fresh manager to pick up the config
        $manager = new MakerCheckerRequestManager($this->app);

        $this->expectException(RequestCannotBeChecked::class);
        $manager->reject($request, $rejector);
    }

    public function test_non_expired_request_can_be_approved(): void
    {
        $this->app['config']->set('maker-checker.request_expiration_in_minutes', 120);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Fresh Test', 'content' => 'C', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->save();

        $result = MakerChecker::approve($request, $approver);
        $this->assertEquals(RequestStatus::APPROVED, $result->status);
    }

    // =========================================================================
    // FulfillmentException Paths
    // =========================================================================

    public function test_fulfillment_exception_invalid_payload_for_create(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'C', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->save();

        // Corrupt the payload to be null
        MakerCheckerRequest::withoutEvents(function () use ($request) {
            DB::table($request->getTable())
                ->where('id', $request->id)
                ->update(['payload' => null]);
        });

        $request = $request->fresh();

        $this->expectException(RequestCouldNotBeProcessed::class);
        MakerChecker::approve($request, $approver);
    }

    public function test_fulfillment_exception_invalid_executable_class(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toExecute(TestExecutable::class, ['action_id' => 1])
            ->withApprovals(['default' => 1])
            ->save();

        // Corrupt the executable to a non-existent class
        MakerCheckerRequest::withoutEvents(function () use ($request) {
            DB::table($request->getTable())
                ->where('id', $request->id)
                ->update(['executable' => 'NonExistent\\Class\\Name']);
        });

        $request = $request->fresh();

        $this->expectException(RequestCouldNotBeProcessed::class);
        MakerChecker::approve($request, $approver);
    }

    public function test_fulfillment_exception_invalid_subject_class_for_create(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Test', 'content' => 'C', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->save();

        // Corrupt the subject_type to a non-existent class
        MakerCheckerRequest::withoutEvents(function () use ($request) {
            DB::table($request->getTable())
                ->where('id', $request->id)
                ->update(['subject_type' => 'NonExistent\\Model']);
        });

        $request = $request->fresh();

        $this->expectException(RequestCouldNotBeProcessed::class);
        MakerChecker::approve($request, $approver);
    }

    public function test_fulfillment_exception_invalid_payload_for_update(): void
    {
        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $post = Post::create(['title' => 'Original', 'content' => 'Content', 'user_id' => $maker->id]);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toUpdate($post, ['title' => 'Updated'])
            ->withApprovals(['default' => 1])
            ->save();

        // Corrupt the payload to be null
        MakerCheckerRequest::withoutEvents(function () use ($request) {
            DB::table($request->getTable())
                ->where('id', $request->id)
                ->update(['payload' => null]);
        });

        $request = $request->fresh();

        $this->expectException(RequestCouldNotBeProcessed::class);
        MakerChecker::approve($request, $approver);
    }

    // =========================================================================
    // Audit Service Tests
    // =========================================================================

    public function test_audit_log_created_on_approval(): void
    {
        $this->app['config']->set('maker-checker.audit.enabled', true);
        $this->app['config']->set('maker-checker.audit.driver', 'database');

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com', 'role' => 'admin']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Audit Test', 'content' => 'C', 'user_id' => $maker->id])
            ->withApprovals(['admin' => 1])
            ->save();

        MakerChecker::approve($request, $approver, 'admin');

        $auditLog = DB::table('maker_checker_audit_logs')->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('approved', $auditLog->action);
        $this->assertEquals($approver->id, $auditLog->actor_id);
        $this->assertEquals('pending', $auditLog->previous_status);
        $this->assertEquals('approved', $auditLog->new_status);
    }

    public function test_audit_log_created_on_rejection(): void
    {
        $this->app['config']->set('maker-checker.audit.enabled', true);
        $this->app['config']->set('maker-checker.audit.driver', 'database');

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $rejector = User::create(['name' => 'Rejector', 'email' => 'rejector@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Audit Reject', 'content' => 'C', 'user_id' => $maker->id])
            ->save();

        MakerChecker::reject($request, $rejector, 'Not good');

        $auditLog = DB::table('maker_checker_audit_logs')->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('rejected', $auditLog->action);
        $this->assertEquals($rejector->id, $auditLog->actor_id);
    }

    public function test_audit_log_created_on_cancellation(): void
    {
        $this->app['config']->set('maker-checker.audit.enabled', true);
        $this->app['config']->set('maker-checker.audit.driver', 'database');

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Audit Cancel', 'content' => 'C', 'user_id' => $maker->id])
            ->save();

        MakerChecker::cancel($request, $maker);

        $auditLog = DB::table('maker_checker_audit_logs')->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('cancelled', $auditLog->action);
        $this->assertEquals($maker->id, $auditLog->actor_id);
    }

    public function test_audit_disabled_does_not_write_log(): void
    {
        $this->app['config']->set('maker-checker.audit.enabled', false);
        $this->app['config']->set('maker-checker.audit.driver', 'database');

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'No Audit', 'content' => 'C', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->save();

        MakerChecker::approve($request, $approver);

        $count = DB::table('maker_checker_audit_logs')->count();
        $this->assertEquals(0, $count);
    }

    public function test_audit_log_driver(): void
    {
        $this->app['config']->set('maker-checker.audit.enabled', true);
        $this->app['config']->set('maker-checker.audit.driver', 'log');

        $maker = User::create(['name' => 'Maker', 'email' => 'maker@example.com']);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.com']);

        $request = MakerChecker::request()
            ->madeBy($maker)
            ->toCreate(Post::class, ['title' => 'Log Audit', 'content' => 'C', 'user_id' => $maker->id])
            ->withApprovals(['default' => 1])
            ->save();

        // Log driver should not throw an exception
        MakerChecker::approve($request, $approver);

        // Should NOT write to database when driver is 'log'
        $count = DB::table('maker_checker_audit_logs')->count();
        $this->assertEquals(0, $count);
    }

    public function test_audit_service_is_registered_as_singleton(): void
    {
        $service1 = $this->app->make(AuditService::class);
        $service2 = $this->app->make(AuditService::class);

        $this->assertSame($service1, $service2);
    }

    // =========================================================================
    // FulfillmentException Static Methods
    // =========================================================================

    public function test_fulfillment_exception_factory_methods(): void
    {
        $invalidPayload = FulfillmentException::invalidPayload('an array');
        $this->assertStringContainsString('an array', $invalidPayload->getMessage());

        $invalidExecutable = FulfillmentException::invalidExecutable('class not found');
        $this->assertStringContainsString('class not found', $invalidExecutable->getMessage());

        $generic = FulfillmentException::create('something went wrong');
        $this->assertStringContainsString('something went wrong', $generic->getMessage());

        $withPrevious = FulfillmentException::create('error', new \RuntimeException('cause'));
        $this->assertNotNull($withPrevious->getPrevious());
    }
}
