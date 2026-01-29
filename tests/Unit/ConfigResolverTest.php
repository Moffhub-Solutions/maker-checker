<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\ConfigResolver;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerConfig;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;
use Moffhub\MakerChecker\Tests\Fixtures\TestExecutable;

class ConfigResolverTest extends BaseTestCase
{
    public function test_resolves_approvals_from_configurable_interface(): void
    {
        $config = [
            'config_driver' => 'file',
            'default_approval_count' => 1,
            'models' => [],
        ];

        $resolver = new ConfigResolver($config);

        $approvals = $resolver->getApprovals(Post::class, RequestType::CREATE);

        $this->assertEquals(['admin' => 1], $approvals);
    }

    public function test_resolves_approvals_from_config_file(): void
    {
        $config = [
            'config_driver' => 'file',
            'default_approval_count' => 1,
            'models' => [
                User::class => [
                    'approvals' => [
                        'create' => ['manager' => 2],
                        'update' => 1,
                    ],
                ],
            ],
        ];

        $resolver = new ConfigResolver($config);

        // User doesn't implement MakerCheckerConfigurable, so it should fall back to config
        $approvals = $resolver->getApprovals(User::class, RequestType::CREATE);

        $this->assertEquals(['manager' => 2], $approvals);
    }

    public function test_resolves_approvals_with_integer_normalization(): void
    {
        $config = [
            'config_driver' => 'file',
            'default_approval_count' => 1,
            'models' => [
                User::class => [
                    'approvals' => [
                        'update' => 3,
                    ],
                ],
            ],
        ];

        $resolver = new ConfigResolver($config);

        $approvals = $resolver->getApprovals(User::class, RequestType::UPDATE);

        $this->assertEquals(['default' => 3], $approvals);
    }

    public function test_resolves_unique_fields_from_configurable_interface(): void
    {
        $config = [
            'config_driver' => 'file',
            'models' => [],
        ];

        $resolver = new ConfigResolver($config);

        $uniqueFields = $resolver->getUniqueFields(Post::class, RequestType::CREATE);

        $this->assertEquals(['title'], $uniqueFields);
    }

    public function test_resolves_unique_fields_from_config_file(): void
    {
        $config = [
            'config_driver' => 'file',
            'models' => [
                User::class => [
                    'unique_fields' => [
                        'create' => ['email'],
                    ],
                ],
            ],
        ];

        $resolver = new ConfigResolver($config);

        $uniqueFields = $resolver->getUniqueFields(User::class, RequestType::CREATE);

        $this->assertEquals(['email'], $uniqueFields);
    }

    public function test_is_required_from_configurable_interface(): void
    {
        $config = [
            'config_driver' => 'file',
            'models' => [],
        ];

        $resolver = new ConfigResolver($config);

        $this->assertTrue($resolver->isRequired(Post::class, RequestType::CREATE));
        $this->assertTrue($resolver->isRequired(Post::class, RequestType::DELETE));
    }

    public function test_is_required_from_config_file(): void
    {
        $config = [
            'config_driver' => 'file',
            'models' => [
                User::class => [
                    'required_for' => ['create', 'update'],
                ],
            ],
        ];

        $resolver = new ConfigResolver($config);

        $this->assertTrue($resolver->isRequired(User::class, RequestType::CREATE));
        $this->assertTrue($resolver->isRequired(User::class, RequestType::UPDATE));
        $this->assertFalse($resolver->isRequired(User::class, RequestType::DELETE));
    }

    public function test_get_description_from_configurable_interface(): void
    {
        $config = [
            'config_driver' => 'file',
            'models' => [],
        ];

        $resolver = new ConfigResolver($config);

        $description = $resolver->getDescription(Post::class, RequestType::CREATE, ['title' => 'My Post']);

        $this->assertEquals('Create post: My Post', $description);
    }

    public function test_get_description_default_fallback(): void
    {
        $config = [
            'config_driver' => 'file',
            'models' => [],
        ];

        $resolver = new ConfigResolver($config);

        $description = $resolver->getDescription(User::class, RequestType::CREATE, []);

        $this->assertEquals('Create new User', $description);
    }

    public function test_global_approvals_fallback(): void
    {
        $config = [
            'config_driver' => 'file',
            'default_approval_count' => 1,
            'models' => [],
            'global_approvals' => [
                'create' => ['reviewer' => 1],
                'delete' => ['admin' => 2, 'manager' => 1],
            ],
        ];

        $resolver = new ConfigResolver($config);

        // User doesn't have specific config, so falls back to global
        $approvals = $resolver->getApprovals(User::class, RequestType::CREATE);
        $this->assertEquals(['reviewer' => 1], $approvals);

        $approvals = $resolver->getApprovals(User::class, RequestType::DELETE);
        $this->assertEquals(['admin' => 2, 'manager' => 1], $approvals);
    }

    public function test_executable_specific_config(): void
    {
        $config = [
            'config_driver' => 'file',
            'default_approval_count' => 1,
            'models' => [],
            'executables' => [
                TestExecutable::class => [
                    'approvals' => ['admin' => 1],
                    'unique_fields' => ['action_id'],
                ],
            ],
        ];

        $resolver = new ConfigResolver($config);

        $approvals = $resolver->getApprovals(
            TestExecutable::class,
            RequestType::EXECUTE,
            TestExecutable::class
        );
        $this->assertEquals(['admin' => 1], $approvals);

        $uniqueFields = $resolver->getUniqueFields(
            TestExecutable::class,
            RequestType::EXECUTE,
            TestExecutable::class
        );
        $this->assertEquals(['action_id'], $uniqueFields);
    }

    public function test_database_driver_resolves_from_database(): void
    {
        $config = [
            'config_driver' => 'database',
            'default_approval_count' => 1,
            'models' => [],
        ];

        // Create a database config entry
        MakerCheckerConfig::create([
            'configurable_type' => User::class,
            'action' => 'create',
            'approvals' => ['supervisor' => 2],
            'unique_fields' => ['email', 'name'],
            'is_active' => true,
        ]);

        $resolver = new ConfigResolver($config);

        $approvals = $resolver->getApprovals(User::class, RequestType::CREATE);
        $this->assertEquals(['supervisor' => 2], $approvals);

        $uniqueFields = $resolver->getUniqueFields(User::class, RequestType::CREATE);
        $this->assertEquals(['email', 'name'], $uniqueFields);
    }

    public function test_database_driver_with_team_id(): void
    {
        $config = [
            'config_driver' => 'database',
            'default_approval_count' => 1,
            'models' => [],
        ];

        // Create config for team 1
        MakerCheckerConfig::create([
            'configurable_type' => User::class,
            'action' => 'create',
            'approvals' => ['team_lead' => 1],
            'is_active' => true,
            'team_id' => 1,
        ]);

        // Create config for team 2
        MakerCheckerConfig::create([
            'configurable_type' => User::class,
            'action' => 'create',
            'approvals' => ['manager' => 2],
            'is_active' => true,
            'team_id' => 2,
        ]);

        $resolver = new ConfigResolver($config);

        $approvalsTeam1 = $resolver->getApprovals(User::class, RequestType::CREATE, null, 1);
        $this->assertEquals(['team_lead' => 1], $approvalsTeam1);

        $approvalsTeam2 = $resolver->getApprovals(User::class, RequestType::CREATE, null, 2);
        $this->assertEquals(['manager' => 2], $approvalsTeam2);
    }

    public function test_configurable_interface_takes_priority_over_database(): void
    {
        $config = [
            'config_driver' => 'database',
            'default_approval_count' => 1,
            'models' => [],
        ];

        // Create a database config entry for Post
        MakerCheckerConfig::create([
            'configurable_type' => Post::class,
            'action' => 'create',
            'approvals' => ['db_admin' => 5],
            'is_active' => true,
        ]);

        $resolver = new ConfigResolver($config);

        // Post implements MakerCheckerConfigurable, so it should use the interface config
        $approvals = $resolver->getApprovals(Post::class, RequestType::CREATE);
        $this->assertEquals(['admin' => 1], $approvals);
    }

    public function test_default_approval_count(): void
    {
        $config = [
            'config_driver' => 'file',
            'default_approval_count' => 3,
            'models' => [],
        ];

        $resolver = new ConfigResolver($config);

        $this->assertEquals(3, $resolver->getDefaultApprovalCount());
    }

    public function test_uses_database_driver_check(): void
    {
        $fileConfig = [
            'config_driver' => 'file',
        ];

        $dbConfig = [
            'config_driver' => 'database',
        ];

        $fileResolver = new ConfigResolver($fileConfig);
        $dbResolver = new ConfigResolver($dbConfig);

        $this->assertFalse($fileResolver->usesDatabaseDriver());
        $this->assertTrue($dbResolver->usesDatabaseDriver());
    }
}
