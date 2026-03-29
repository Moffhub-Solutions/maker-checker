<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerDelegation;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Services\DefaultApproverResolver;
use Moffhub\MakerChecker\Services\DelegationService;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class DelegationTest extends BaseTestCase
{
    private User $admin;

    private User $user;

    private User $delegate;

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

        $this->delegate = User::create([
            'name' => 'Delegate User',
            'email' => 'delegate@example.com',
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

    public function test_can_create_delegation(): void
    {
        $service = app(DelegationService::class);

        $delegation = $service->create($this->admin, $this->delegate, 'approvals');

        $this->assertDatabaseHas('maker_checker_delegations', [
            'delegator_type' => User::class,
            'delegator_id' => $this->admin->id,
            'delegate_type' => User::class,
            'delegate_id' => $this->delegate->id,
            'scope' => 'approvals',
        ]);

        $this->assertTrue($delegation->isActive());
    }

    public function test_can_create_delegation_with_expiry(): void
    {
        $service = app(DelegationService::class);

        $expiresAt = Carbon::now()->addDays(7);
        $delegation = $service->create($this->admin, $this->delegate, null, $expiresAt);

        $this->assertTrue($delegation->isActive());
        $this->assertNotNull($delegation->expires_at);
    }

    public function test_expired_delegation_is_not_active(): void
    {
        $service = app(DelegationService::class);

        $delegation = $service->create($this->admin, $this->delegate, null, Carbon::now()->subDay());

        $this->assertTrue($delegation->isExpired());
        $this->assertFalse($delegation->isActive());
    }

    public function test_can_revoke_delegation(): void
    {
        $service = app(DelegationService::class);

        $delegation = $service->create($this->admin, $this->delegate);
        $result = $service->revoke($delegation->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('maker_checker_delegations', ['id' => $delegation->id]);
    }

    public function test_revoke_nonexistent_delegation_returns_false(): void
    {
        $service = app(DelegationService::class);

        $result = $service->revoke(99999);

        $this->assertFalse($result);
    }

    public function test_get_active_delegations_for_user(): void
    {
        $service = app(DelegationService::class);

        $service->create($this->admin, $this->delegate, 'scope1');
        $service->create($this->admin, $this->user, 'scope2');
        $service->create($this->admin, $this->delegate, null, Carbon::now()->subDay()); // expired

        $active = $service->getActiveDelegationsFor($this->admin);

        $this->assertCount(2, $active);
    }

    public function test_active_scope_filters_correctly(): void
    {
        MakerCheckerDelegation::create([
            'delegator_type' => User::class,
            'delegator_id' => $this->admin->id,
            'delegate_type' => User::class,
            'delegate_id' => $this->delegate->id,
            'expires_at' => now()->addDay(),
        ]);

        MakerCheckerDelegation::create([
            'delegator_type' => User::class,
            'delegator_id' => $this->admin->id,
            'delegate_type' => User::class,
            'delegate_id' => $this->user->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertEquals(1, MakerCheckerDelegation::active()->count());
        $this->assertEquals(1, MakerCheckerDelegation::expired()->count());
    }

    public function test_get_all_approvers_includes_delegates(): void
    {
        $request = $this->createRequest(['required_approvals' => ['admin' => 1]]);

        // Create delegation from admin to delegate
        app(DelegationService::class)->create($this->admin, $this->delegate);

        $resolver = app(DefaultApproverResolver::class);
        $approvers = $resolver->getAllApprovers($request);

        // Should include the admin and the delegate
        $approverIds = $approvers->pluck('id')->toArray();
        $this->assertContains($this->admin->id, $approverIds);
        $this->assertContains($this->delegate->id, $approverIds);
    }

    public function test_delegation_api_create(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/delegations', [
                'delegate_id' => $this->delegate->id,
                'scope' => 'test-scope',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.delegate_id', $this->delegate->id)
            ->assertJsonPath('data.scope', 'test-scope');
    }

    public function test_delegation_api_list(): void
    {
        app(DelegationService::class)->create($this->admin, $this->delegate, 'test');

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/delegations');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_delegation_api_delete(): void
    {
        $delegation = app(DelegationService::class)->create($this->admin, $this->delegate);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/maker-checker/delegations/{$delegation->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('maker_checker_delegations', ['id' => $delegation->id]);
    }

    public function test_delegation_api_delete_only_own_delegations(): void
    {
        $delegation = app(DelegationService::class)->create($this->admin, $this->delegate);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/maker-checker/delegations/{$delegation->id}");

        $response->assertStatus(403);
    }

    public function test_delegation_api_delete_nonexistent(): void
    {
        $response = $this->actingAs($this->admin)
            ->deleteJson('/api/maker-checker/delegations/99999');

        $response->assertStatus(404);
    }
}
