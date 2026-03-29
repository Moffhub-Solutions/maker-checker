<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerApprovalNote;
use Moffhub\MakerChecker\Models\MakerCheckerConfig;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;

class FactoryTest extends BaseTestCase
{
    public function test_request_factory_creates_default_instance(): void
    {
        $request = MakerCheckerRequest::factory()->create();

        $this->assertNotNull($request->id);
        $this->assertNotNull($request->code);
        $this->assertNotNull($request->description);
        $this->assertEquals(RequestType::CREATE, $request->type);
        $this->assertEquals(RequestStatus::PENDING, $request->status);
        $this->assertNotNull($request->maker_type);
        $this->assertNotNull($request->maker_id);
        $this->assertNotNull($request->made_at);
    }

    public function test_request_factory_approved_state(): void
    {
        $request = MakerCheckerRequest::factory()->approved()->create();

        $this->assertEquals(RequestStatus::APPROVED, $request->status);
        $this->assertNotNull($request->checker_type);
        $this->assertNotNull($request->checker_id);
        $this->assertNotNull($request->checked_at);
    }

    public function test_request_factory_rejected_state(): void
    {
        $request = MakerCheckerRequest::factory()->rejected()->create();

        $this->assertEquals(RequestStatus::REJECTED, $request->status);
        $this->assertNotNull($request->checked_at);
    }

    public function test_request_factory_pending_state(): void
    {
        $request = MakerCheckerRequest::factory()->pending()->create();

        $this->assertEquals(RequestStatus::PENDING, $request->status);
        $this->assertNull($request->checker_type);
        $this->assertNull($request->checker_id);
        $this->assertNull($request->checked_at);
    }

    public function test_request_factory_cancelled_state(): void
    {
        $request = MakerCheckerRequest::factory()->cancelled()->create();

        $this->assertEquals(RequestStatus::CANCELLED, $request->status);
    }

    public function test_request_factory_for_create(): void
    {
        $request = MakerCheckerRequest::factory()->forCreate()->create();

        $this->assertEquals(RequestType::CREATE, $request->type);
    }

    public function test_request_factory_for_update(): void
    {
        $request = MakerCheckerRequest::factory()->forUpdate()->create();

        $this->assertEquals(RequestType::UPDATE, $request->type);
        $this->assertNotNull($request->subject_id);
    }

    public function test_request_factory_for_delete(): void
    {
        $request = MakerCheckerRequest::factory()->forDelete()->create();

        $this->assertEquals(RequestType::DELETE, $request->type);
        $this->assertNotNull($request->subject_id);
    }

    public function test_request_factory_for_execute(): void
    {
        $request = MakerCheckerRequest::factory()->forExecute()->create();

        $this->assertEquals(RequestType::EXECUTE, $request->type);
    }

    public function test_request_factory_with_approvals(): void
    {
        $approvals = ['roles' => ['admin' => 2]];
        $request = MakerCheckerRequest::factory()->withApprovals($approvals)->create();

        $this->assertEquals($approvals, $request->required_approvals);
    }

    public function test_request_factory_with_payload(): void
    {
        $payload = ['email' => 'test@example.com', 'name' => 'Test'];
        $request = MakerCheckerRequest::factory()->withPayload($payload)->create();

        $this->assertEquals($payload, $request->payload);
    }

    public function test_request_factory_creates_multiple(): void
    {
        $requests = MakerCheckerRequest::factory()->count(3)->create();

        $this->assertCount(3, $requests);
    }

    public function test_config_factory_creates_default_instance(): void
    {
        $config = MakerCheckerConfig::factory()->create();

        $this->assertNotNull($config->id);
        $this->assertNotNull($config->configurable_type);
        $this->assertEquals(RequestType::CREATE->value, $config->action);
        $this->assertTrue($config->is_active);
        $this->assertEquals(0, $config->priority);
    }

    public function test_config_factory_inactive_state(): void
    {
        $config = MakerCheckerConfig::factory()->inactive()->create();

        $this->assertFalse($config->is_active);
    }

    public function test_config_factory_for_action(): void
    {
        $config = MakerCheckerConfig::factory()->forAction(RequestType::DELETE)->create();

        $this->assertEquals(RequestType::DELETE->value, $config->action);
    }

    public function test_config_factory_for_model(): void
    {
        $config = MakerCheckerConfig::factory()->forModel('App\\Models\\Post')->create();

        $this->assertEquals('App\\Models\\Post', $config->configurable_type);
    }

    public function test_config_factory_for_all_actions(): void
    {
        $config = MakerCheckerConfig::factory()->forAllActions()->create();

        $this->assertNull($config->action);
    }

    public function test_config_factory_for_team(): void
    {
        $config = MakerCheckerConfig::factory()->forTeam(5)->create();

        $this->assertEquals(5, $config->team_id);
    }

    public function test_config_factory_with_priority(): void
    {
        $config = MakerCheckerConfig::factory()->withPriority(10)->create();

        $this->assertEquals(10, $config->priority);
    }

    public function test_config_factory_with_conditions(): void
    {
        $conditions = ['rules' => [['field' => 'amount', 'operator' => '>', 'value' => 1000]]];
        $config = MakerCheckerConfig::factory()->withConditions($conditions)->create();

        $this->assertEquals($conditions, $config->conditions);
    }

    public function test_approval_note_factory_creates_default_instance(): void
    {
        $request = MakerCheckerRequest::factory()->create();
        $note = MakerCheckerApprovalNote::factory()->create(['request_id' => $request->id]);

        $this->assertNotNull($note->id);
        $this->assertEquals($request->id, $note->request_id);
        $this->assertNotNull($note->user_type);
        $this->assertNotNull($note->user_id);
        $this->assertEquals('approved', $note->action);
        $this->assertNotNull($note->note);
    }

    public function test_approval_note_factory_for_rejection(): void
    {
        $request = MakerCheckerRequest::factory()->create();
        $note = MakerCheckerApprovalNote::factory()->forRejection()->create(['request_id' => $request->id]);

        $this->assertEquals('rejected', $note->action);
    }

    public function test_request_factory_make_does_not_persist(): void
    {
        $request = MakerCheckerRequest::factory()->make();

        $this->assertNull($request->id);
        $this->assertNotNull($request->description);
        $this->assertEquals(RequestStatus::PENDING, $request->status);
    }
}
