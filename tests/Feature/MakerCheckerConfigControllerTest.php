<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Moffhub\MakerChecker\Models\MakerCheckerConfig;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class MakerCheckerConfigControllerTest extends BaseTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_can_list_configs(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => User::class,
            'action' => 'update',
            'approvals' => ['manager' => 2],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/configs');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_configs_by_type(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => User::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/configs?configurable_type='.urlencode(Post::class));

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_create_config(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                'configurable_type' => Post::class,
                'action' => 'create',
                'approvals' => ['admin' => 2, 'manager' => 1],
                'unique_fields' => ['title'],
                'description' => 'Config for Post creation',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Configuration created successfully')
            ->assertJsonPath('data.configurable_type', Post::class)
            ->assertJsonPath('data.action', 'create');

        $this->assertDatabaseHas('maker_checker_configs', [
            'configurable_type' => Post::class,
            'action' => 'create',
        ]);
    }

    public function test_can_show_single_config(): void
    {
        $config = MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'unique_fields' => ['title'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/maker-checker/configs/{$config->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $config->id)
            ->assertJsonPath('data.configurable_type', Post::class);
    }

    public function test_can_update_config(): void
    {
        $config = MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/maker-checker/configs/{$config->id}", [
                'approvals' => ['admin' => 3, 'reviewer' => 1],
                'description' => 'Updated description',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Configuration updated successfully');

        $config->refresh();
        $this->assertEquals(['admin' => 3, 'reviewer' => 1], $config->getApprovals());
        $this->assertEquals('Updated description', $config->description);
    }

    public function test_can_delete_config(): void
    {
        $config = MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/maker-checker/configs/{$config->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Configuration deleted successfully');

        $this->assertDatabaseMissing('maker_checker_configs', [
            'id' => $config->id,
        ]);
    }

    public function test_can_enable_config(): void
    {
        $config = MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/maker-checker/configs/{$config->id}/enable");

        $response->assertOk()
            ->assertJsonPath('message', 'Configuration enabled successfully');

        $config->refresh();
        $this->assertTrue($config->is_active);
    }

    public function test_can_disable_config(): void
    {
        $config = MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/maker-checker/configs/{$config->id}/disable");

        $response->assertOk()
            ->assertJsonPath('message', 'Configuration disabled successfully');

        $config->refresh();
        $this->assertFalse($config->is_active);
    }

    public function test_can_import_configs(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs/import', [
                'configs' => [
                    [
                        'configurable_type' => Post::class,
                        'action' => 'create',
                        'approvals' => ['admin' => 1],
                    ],
                    [
                        'configurable_type' => Post::class,
                        'action' => 'update',
                        'approvals' => ['admin' => 2],
                    ],
                    [
                        'configurable_type' => User::class,
                        'action' => 'delete',
                        'approvals' => ['superadmin' => 1],
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', '3 configuration(s) imported successfully');

        $this->assertDatabaseCount('maker_checker_configs', 3);
    }

    public function test_can_export_configs(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'unique_fields' => ['title'],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => User::class,
            'action' => 'update',
            'approvals' => ['manager' => 2],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/configs/export');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_can_get_actions(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/configs/actions');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['value', 'label'],
                ],
            ]);

        $values = collect($response->json('data'))->pluck('value')->toArray();
        $this->assertContains('create', $values);
        $this->assertContains('update', $values);
        $this->assertContains('delete', $values);
        $this->assertContains('execute', $values);
    }

    public function test_can_get_configurable_types(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => User::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/configs/types');

        $response->assertOk();
        $this->assertContains(Post::class, $response->json('data'));
        $this->assertContains(User::class, $response->json('data'));
    }

    public function test_validation_on_create(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maker-checker/configs', [
                // Missing required configurable_type
                'action' => 'create',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['configurable_type']);
    }

    public function test_configs_filtered_by_team_id(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 1],
            'team_id' => 1,
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['admin' => 2],
            'team_id' => 2,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/configs?team_id=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
