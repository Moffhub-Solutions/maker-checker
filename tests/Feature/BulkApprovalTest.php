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

class BulkApprovalTest extends BaseTestCase
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

    public function test_bulk_approve_multiple_requests(): void
    {
        $req1 = $this->createRequest(['required_approvals' => ['admin' => 1]]);
        $req2 = $this->createRequest(['required_approvals' => ['admin' => 1]]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/requests/bulk-approve', [
                'request_ids' => [$req1->id, $req2->id],
                'role' => 'admin',
            ]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.approved'));
        $this->assertEquals(0, $response->json('data.failed'));
        $this->assertEmpty($response->json('data.errors'));
    }

    public function test_bulk_approve_with_nonexistent_request(): void
    {
        $req1 = $this->createRequest(['required_approvals' => ['admin' => 1]]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/requests/bulk-approve', [
                'request_ids' => [$req1->id, 99999],
                'role' => 'admin',
            ]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.approved'));
        $this->assertEquals(1, $response->json('data.failed'));
        $this->assertNotEmpty($response->json('data.errors'));
    }

    public function test_bulk_approve_with_non_actionable_request(): void
    {
        $req1 = $this->createRequest(['required_approvals' => ['admin' => 1]]);
        $req2 = $this->createRequest([
            'required_approvals' => ['admin' => 1],
            'status' => RequestStatus::REJECTED,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/requests/bulk-approve', [
                'request_ids' => [$req1->id, $req2->id],
                'role' => 'admin',
            ]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.approved'));
        $this->assertEquals(1, $response->json('data.failed'));
    }

    public function test_bulk_approve_validates_request_ids(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/requests/bulk-approve', []);

        $response->assertStatus(422);
    }

    public function test_bulk_approve_with_remarks(): void
    {
        $req1 = $this->createRequest(['required_approvals' => ['admin' => 1]]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/requests/bulk-approve', [
                'request_ids' => [$req1->id],
                'role' => 'admin',
                'remarks' => 'Bulk approved',
            ]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.approved'));
    }
}
