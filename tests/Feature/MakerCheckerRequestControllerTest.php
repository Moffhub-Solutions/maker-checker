<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Illuminate\Support\Str;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class MakerCheckerRequestControllerTest extends BaseTestCase
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

    /**
     * Create a test request with required fields.
     * This handles the guarded 'code' field properly.
     */
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
        ];

        $merged = array_merge($defaults, $attributes);
        $code = $merged['code'] ?? (string) Str::uuid();
        unset($merged['code']);

        $request = new MakerCheckerRequest($merged);
        $request->code = $code;
        $request->save();

        return $request;
    }

    public function test_can_list_requests(): void
    {
        $this->createRequest(['code' => 'req-1', 'description' => 'Test request 1']);
        $this->createRequest([
            'code' => 'req-2',
            'description' => 'Test request 2',
            'type' => RequestType::UPDATE,
            'status' => RequestStatus::APPROVED,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/requests');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    // Index uses SIMPLE format: id, description, status, type (no code)
                    '*' => ['id', 'description', 'type', 'status'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_filter_requests_by_status(): void
    {
        $this->createRequest(['code' => 'pending-1', 'description' => 'Pending request']);
        $this->createRequest([
            'code' => 'approved-1',
            'description' => 'Approved request',
            'status' => RequestStatus::APPROVED,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/requests?status=pending');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        // Index uses SIMPLE format which doesn't include code, check description instead
        $this->assertEquals('Pending request', $response->json('data.0.description'));
    }

    public function test_can_filter_requests_by_type(): void
    {
        $this->createRequest(['code' => 'create-1', 'description' => 'Create request']);
        $this->createRequest([
            'code' => 'update-1',
            'description' => 'Update request',
            'type' => RequestType::UPDATE,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/requests?type=create');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        // Index uses SIMPLE format which doesn't include code, check description instead
        $this->assertEquals('Create request', $response->json('data.0.description'));
    }

    public function test_can_show_single_request(): void
    {
        $request = $this->createRequest([
            'code' => 'req-1',
            'payload' => ['title' => 'Test'],
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/maker-checker/requests/{$request->id}");

        $response->assertOk();
        // Show uses BASE format which returns id as string
        $this->assertEquals((string) $request->id, $response->json('data.id'));
        $this->assertEquals('req-1', $response->json('data.code'));
    }

    public function test_can_approve_request(): void
    {
        $request = $this->createRequest([
            'code' => 'req-1',
            'payload' => ['title' => 'Test', 'content' => 'Content', 'user_id' => $this->user->id],
            'required_approvals' => ['admin' => 1],
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/maker-checker/requests/{$request->id}/approve", [
                'role' => 'admin',
                'remarks' => 'Approved',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Request approved successfully');

        $request->refresh();
        $this->assertEquals(RequestStatus::APPROVED, $request->status);
    }

    public function test_can_reject_request(): void
    {
        $request = $this->createRequest([
            'code' => 'req-1',
            'payload' => ['title' => 'Test'],
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/maker-checker/requests/{$request->id}/reject", [
                'remarks' => 'Not acceptable',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Request rejected successfully');

        $request->refresh();
        $this->assertEquals(RequestStatus::REJECTED, $request->status);
        $this->assertEquals('Not acceptable', $request->remarks);
    }

    public function test_can_cancel_own_request(): void
    {
        $request = $this->createRequest([
            'code' => 'req-1',
            'payload' => ['title' => 'Test'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/maker-checker/requests/{$request->id}/cancel", [
                'remarks' => 'Changed my mind',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Request cancelled successfully');

        $request->refresh();
        $this->assertEquals(RequestStatus::CANCELLED, $request->status);
    }

    public function test_cannot_cancel_other_users_request(): void
    {
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
        ]);

        $request = $this->createRequest([
            'code' => 'req-1',
            'payload' => ['title' => 'Test'],
            'maker' => $otherUser,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/maker-checker/requests/{$request->id}/cancel");

        $response->assertStatus(500); // Exception thrown
    }

    public function test_can_get_approvals_for_request(): void
    {
        $approver = User::create([
            'name' => 'Approver',
            'email' => 'approver@example.com',
            'role' => 'admin',
        ]);

        $request = $this->createRequest([
            'code' => 'req-1',
            'status' => RequestStatus::PARTIALLY_APPROVED,
            'payload' => ['title' => 'Test'],
            'required_approvals' => ['admin' => 2],
            'approvals' => [
                [
                    'checker_type' => User::class,
                    'checker_id' => $approver->id,
                    'role' => 'admin',
                    'approved_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/maker-checker/requests/{$request->id}/approvals");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'required_approvals',
                    'current_approvals',
                    'is_fully_approved',
                    'pending_roles',
                ],
            ]);

        $this->assertFalse($response->json('data.is_fully_approved'));
        $this->assertEquals(['admin' => 1], $response->json('data.pending_roles'));
    }

    public function test_can_get_statistics(): void
    {
        $this->createRequest(['code' => 'pending-1', 'description' => 'Pending']);
        $this->createRequest([
            'code' => 'approved-1',
            'description' => 'Approved',
            'type' => RequestType::UPDATE,
            'status' => RequestStatus::APPROVED,
        ]);
        $this->createRequest([
            'code' => 'partial-1',
            'description' => 'Partial',
            'type' => RequestType::DELETE,
            'status' => RequestStatus::PARTIALLY_APPROVED,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/requests/statistics');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total',
                    'by_status',
                    'by_type',
                    'actionable',
                ],
            ]);

        $this->assertEquals(3, $response->json('data.total'));
        $this->assertEquals(2, $response->json('data.actionable')); // pending + partially_approved
    }

    public function test_can_get_statuses(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/requests/statuses');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['value', 'label', 'is_actionable', 'is_finalized'],
                ],
            ]);
    }
}
