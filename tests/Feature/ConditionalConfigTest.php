<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Moffhub\MakerChecker\Models\MakerCheckerConfig;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class ConditionalConfigTest extends BaseTestCase
{
    private User $admin;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_can_create_config_with_conditions(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'priority' => 100,
                'conditions' => [
                    'mode' => 'all',
                    'rules' => [
                        ['field' => 'views', 'operator' => '>=', 'value' => 10000],
                    ],
                ],
                'approvals' => ['roles' => ['editor' => 2]],
                'description' => 'High-traffic posts need more approvals',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.has_conditions', true)
            ->assertJsonPath('data.priority', 100)
            ->assertJsonPath('data.conditions.mode', 'all');

        $this->assertDatabaseHas('maker_checker_configs', [
            'configurable_type' => Post::class,
            'priority' => 100,
        ]);
    }

    public function test_can_create_config_without_conditions(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'priority' => 0,
                'approvals' => ['roles' => ['editor' => 1]],
                'description' => 'Default post approval',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.has_conditions', false)
            ->assertJsonPath('data.conditions', null)
            ->assertJsonPath('data.priority', 0);
    }

    public function test_validates_conditions_structure(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'conditions' => [
                    'mode' => 'invalid_mode',
                    'rules' => [],
                ],
                'approvals' => ['roles' => ['editor' => 1]],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Invalid conditions structure');
    }

    public function test_validates_rule_operators(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'conditions' => [
                    'mode' => 'all',
                    'rules' => [
                        ['field' => 'amount', 'operator' => 'bad_op', 'value' => 100],
                    ],
                ],
                'approvals' => ['roles' => ['editor' => 1]],
            ]);

        $response->assertStatus(422);
    }

    public function test_can_update_config_conditions(): void
    {
        $config = MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'priority' => 50,
            'conditions' => null,
            'approvals' => ['roles' => ['editor' => 1]],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/maker-checker/configs/{$config->id}", [
                'conditions' => [
                    'mode' => 'all',
                    'rules' => [
                        ['field' => 'category', 'operator' => '=', 'value' => 'featured'],
                    ],
                ],
                'priority' => 100,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.has_conditions', true)
            ->assertJsonPath('data.priority', 100);

        $config->refresh();
        $this->assertNotNull($config->conditions);
        $this->assertEquals(100, $config->priority);
    }

    public function test_can_remove_conditions(): void
    {
        $config = MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'test', 'operator' => '=', 'value' => 'x']],
            ],
            'approvals' => ['roles' => ['editor' => 1]],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/maker-checker/configs/{$config->id}", [
                'conditions' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.has_conditions', false);

        $config->refresh();
        $this->assertNull($config->conditions);
    }

    public function test_test_conditions_endpoint(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'views', 'operator' => '>=', 'value' => 10000]],
            ],
            'approvals' => ['roles' => ['senior_editor' => 1]],
            'description' => 'High-traffic',
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'priority' => 0,
            'conditions' => null,
            'approvals' => ['roles' => ['editor' => 1]],
            'description' => 'Default',
            'is_active' => true,
        ]);

        // Test with high-traffic payload
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs/test-conditions', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'payload' => ['views' => 15000, 'title' => 'Popular Post'],
            ]);

        $response->assertOk()
            ->assertJsonPath('matching_config.description', 'High-traffic')
            ->assertJsonCount(2, 'evaluated_configs');

        // Test with normal payload
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs/test-conditions', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'payload' => ['views' => 500, 'title' => 'Regular Post'],
            ]);

        $response->assertOk()
            ->assertJsonPath('matching_config.description', 'Default');
    }

    public function test_multiple_configs_same_model_different_priorities(): void
    {
        // Can create multiple configs for same model/action with different priorities
        $response1 = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'priority' => 100,
                'conditions' => ['mode' => 'all', 'rules' => [['field' => 'x', 'operator' => '=', 'value' => 1]]],
                'approvals' => ['roles' => ['admin' => 1]],
            ]);

        $response1->assertStatus(201);

        $response2 = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'priority' => 50, // Different priority
                'conditions' => ['mode' => 'all', 'rules' => [['field' => 'y', 'operator' => '=', 'value' => 2]]],
                'approvals' => ['roles' => ['editor' => 1]],
            ]);

        $response2->assertStatus(201);

        $this->assertDatabaseCount('maker_checker_configs', 2);
    }

    public function test_export_includes_conditions_and_priority(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => ['mode' => 'all', 'rules' => [['field' => 'test', 'operator' => '=', 'value' => 1]]],
            'approvals' => ['roles' => ['admin' => 1]],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/configs/export');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertArrayHasKey('conditions', $data[0]);
        $this->assertArrayHasKey('priority', $data[0]);
    }

    public function test_config_response_includes_conditions_fields(): void
    {
        $config = MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'priority' => 50,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'amount', 'operator' => '>=', 'value' => 1000]],
            ],
            'approvals' => ['roles' => ['admin' => 1]],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/maker-checker/configs/{$config->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'configurable_type',
                    'conditions',
                    'has_conditions',
                    'priority',
                ],
            ])
            ->assertJsonPath('data.has_conditions', true)
            ->assertJsonPath('data.priority', 50)
            ->assertJsonPath('data.conditions.mode', 'all');
    }

    public function test_operators_endpoint(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/configs/operators');

        $response->assertOk();

        $operators = $response->json('data');
        $this->assertContains('=', $operators);
        $this->assertContains('>=', $operators);
        $this->assertContains('in', $operators);
        $this->assertContains('between', $operators);
    }

    public function test_priority_defaults_to_zero(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                'configurable_type' => Post::class,
                'action' => 'create',
                // No priority specified
                'approvals' => ['roles' => ['editor' => 1]],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.priority', 0);
    }
}
