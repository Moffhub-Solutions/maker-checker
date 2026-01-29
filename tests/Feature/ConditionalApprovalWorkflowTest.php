<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerConfig;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Comment;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

/**
 * Tests for conditional approval rules based on payload data.
 *
 * Uses Comment model which doesn't implement MakerCheckerConfigurable,
 * so approval requirements are resolved entirely from database config.
 */
class ConditionalApprovalWorkflowTest extends BaseTestCase
{
    private User $maker;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('maker-checker.config_driver', 'database');

        $this->maker = User::create(['name' => 'Maker', 'email' => 'maker@test.com', 'role' => 'user']);
    }

    public function test_high_value_request_requires_ceo_approval(): void
    {
        // Setup tiered configs
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'budget', 'operator' => '>=', 'value' => 100000]],
            ],
            'approvals' => ['roles' => ['ceo' => 1, 'finance' => 1]],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 0,
            'conditions' => null,
            'approvals' => ['roles' => ['finance' => 1]],
            'is_active' => true,
        ]);

        // Create high-value request
        $request = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Big Project', 'budget' => 150000])
            ->madeBy($this->maker)
            ->save();

        // Should require CEO and Finance
        $this->assertEquals(['ceo' => 1, 'finance' => 1], $request->required_approvals['roles']);
    }

    public function test_low_value_request_requires_only_finance(): void
    {
        // Same setup as above
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'budget', 'operator' => '>=', 'value' => 100000]],
            ],
            'approvals' => ['roles' => ['ceo' => 1, 'finance' => 1]],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 0,
            'conditions' => null,
            'approvals' => ['roles' => ['finance' => 1]],
            'is_active' => true,
        ]);

        // Create low-value request
        $request = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Small Project', 'budget' => 5000])
            ->madeBy($this->maker)
            ->save();

        // Should only require Finance
        $this->assertEquals(['finance' => 1], $request->required_approvals['roles']);
    }

    public function test_explicit_approvals_override_conditional_config(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'budget', 'operator' => '>=', 'value' => 100000]],
            ],
            'approvals' => ['roles' => ['ceo' => 1]],
            'is_active' => true,
        ]);

        // Explicitly set different approvals - should override
        $request = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project', 'budget' => 150000])
            ->madeBy($this->maker)
            ->withApprovals(['roles' => ['manager' => 2]])
            ->save();

        $this->assertEquals(['manager' => 2], $request->required_approvals['roles']);
    }

    public function test_first_matching_config_wins_by_priority(): void
    {
        // Priority 100 - highest
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'amount', 'operator' => '>=', 'value' => 100000]],
            ],
            'approvals' => ['roles' => ['ceo' => 1]],
            'description' => 'High-value',
            'is_active' => true,
        ]);

        // Priority 50
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 50,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'amount', 'operator' => '>=', 'value' => 50000]],
            ],
            'approvals' => ['roles' => ['finance' => 2]],
            'description' => 'Medium-value',
            'is_active' => true,
        ]);

        // Priority 0 - default
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 0,
            'conditions' => null,
            'approvals' => ['roles' => ['finance' => 1]],
            'description' => 'Default',
            'is_active' => true,
        ]);

        // 75k matches both >= 50k and default, but >= 50k has higher priority
        $request = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project', 'amount' => 75000])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals(['finance' => 2], $request->required_approvals['roles']);
    }

    public function test_inactive_configs_are_skipped(): void
    {
        // Inactive config - should be skipped
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [['field' => 'budget', 'operator' => '>=', 'value' => 50000]],
            ],
            'approvals' => ['roles' => ['ceo' => 1]],
            'is_active' => false,
        ]);

        // Active default config
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 0,
            'conditions' => null,
            'approvals' => ['roles' => ['finance' => 1]],
            'is_active' => true,
        ]);

        $request = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project', 'budget' => 75000])
            ->madeBy($this->maker)
            ->save();

        // Should use the active default config
        $this->assertEquals(['finance' => 1], $request->required_approvals['roles']);
    }

    public function test_complex_condition_with_multiple_rules(): void
    {
        // Requires BOTH high amount AND specific region
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [
                    ['field' => 'amount', 'operator' => '>=', 'value' => 50000],
                    ['field' => 'region', 'operator' => 'in', 'value' => ['APAC', 'EMEA']],
                ],
            ],
            'approvals' => ['roles' => ['regional_manager' => 1, 'finance' => 1]],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 0,
            'conditions' => null,
            'approvals' => ['roles' => ['finance' => 1]],
            'is_active' => true,
        ]);

        // High amount + APAC region - should match the conditional config
        $request1 = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project', 'amount' => 75000, 'region' => 'APAC'])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals(['regional_manager' => 1, 'finance' => 1], $request1->required_approvals['roles']);

        // High amount + US region - should NOT match (wrong region), falls to default
        $request2 = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project 2', 'amount' => 75000, 'region' => 'US'])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals(['finance' => 1], $request2->required_approvals['roles']);
    }

    public function test_any_mode_condition(): void
    {
        // Requires high amount OR urgent priority
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'any',
                'rules' => [
                    ['field' => 'amount', 'operator' => '>=', 'value' => 100000],
                    ['field' => 'priority', 'operator' => '=', 'value' => 'urgent'],
                ],
            ],
            'approvals' => ['roles' => ['manager' => 1]],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 0,
            'conditions' => null,
            'approvals' => ['roles' => ['finance' => 1]],
            'is_active' => true,
        ]);

        // Urgent but low amount - should match
        $request1 = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project', 'amount' => 5000, 'priority' => 'urgent'])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals(['manager' => 1], $request1->required_approvals['roles']);

        // High amount but not urgent - should also match
        $request2 = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project 2', 'amount' => 150000, 'priority' => 'normal'])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals(['manager' => 1], $request2->required_approvals['roles']);

        // Neither - falls to default
        $request3 = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project 3', 'amount' => 5000, 'priority' => 'normal'])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals(['finance' => 1], $request3->required_approvals['roles']);
    }

    public function test_no_matching_config_uses_default_approval_count(): void
    {
        // No configs at all
        $this->app['config']->set('maker-checker.default_approval_count', 1);

        $request = MakerChecker::request()
            ->toCreate(Comment::class, ['body' => 'Project'])
            ->madeBy($this->maker)
            ->save();

        // No required_approvals set, will use default_approval_count
        $this->assertEmpty($request->required_approvals ?? []);
    }

    public function test_nested_payload_fields_with_dot_notation(): void
    {
        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 100,
            'conditions' => [
                'mode' => 'all',
                'rules' => [
                    ['field' => 'customer.tier', 'operator' => '=', 'value' => 'enterprise'],
                ],
            ],
            'approvals' => ['roles' => ['sales_manager' => 1]],
            'is_active' => true,
        ]);

        MakerCheckerConfig::create([
            'configurable_type' => Comment::class,
            'action' => 'create',
            'priority' => 0,
            'conditions' => null,
            'approvals' => ['roles' => ['sales' => 1]],
            'is_active' => true,
        ]);

        // Enterprise tier customer
        $request1 = MakerChecker::request()
            ->toCreate(Comment::class, [
                'body' => 'Enterprise Deal',
                'customer' => ['tier' => 'enterprise', 'name' => 'BigCorp'],
            ])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals(['sales_manager' => 1], $request1->required_approvals['roles']);

        // Standard tier customer
        $request2 = MakerChecker::request()
            ->toCreate(Comment::class, [
                'body' => 'Standard Deal',
                'customer' => ['tier' => 'standard', 'name' => 'SmallCo'],
            ])
            ->madeBy($this->maker)
            ->save();

        $this->assertEquals(['sales' => 1], $request2->required_approvals['roles']);
    }
}
