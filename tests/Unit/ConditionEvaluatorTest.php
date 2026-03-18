<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use InvalidArgumentException;
use Moffhub\MakerChecker\Services\ConditionEvaluator;
use Moffhub\MakerChecker\Tests\BaseTestCase;

class ConditionEvaluatorTest extends BaseTestCase
{
    private ConditionEvaluator $evaluator;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConditionEvaluator;
    }

    // =========================================================================
    // Basic Operator Tests
    // =========================================================================

    public function test_equals_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'status', 'operator' => '=', 'value' => 'active']],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['status' => 'active']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['status' => 'inactive']));
    }

    public function test_not_equals_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'status', 'operator' => '!=', 'value' => 'draft']],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['status' => 'published']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['status' => 'draft']));
    }

    public function test_greater_than_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'amount', 'operator' => '>', 'value' => 1000]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 1500]));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => 1000]));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => 500]));
    }

    public function test_greater_than_or_equal_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'amount', 'operator' => '>=', 'value' => 50000]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 75000]));
        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 50000]));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => 49999]));
    }

    public function test_less_than_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'quantity', 'operator' => '<', 'value' => 10]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['quantity' => 5]));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['quantity' => 10]));
    }

    public function test_less_than_or_equal_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'quantity', 'operator' => '<=', 'value' => 10]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['quantity' => 5]));
        $this->assertTrue($this->evaluator->evaluate($conditions, ['quantity' => 10]));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['quantity' => 11]));
    }

    public function test_in_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'region', 'operator' => 'in', 'value' => ['US', 'EU', 'UK']]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['region' => 'US']));
        $this->assertTrue($this->evaluator->evaluate($conditions, ['region' => 'EU']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['region' => 'APAC']));
    }

    public function test_not_in_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'status', 'operator' => 'not_in', 'value' => ['cancelled', 'rejected']]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['status' => 'pending']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['status' => 'cancelled']));
    }

    public function test_contains_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'description', 'operator' => 'contains', 'value' => 'urgent']],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['description' => 'This is urgent!']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['description' => 'Normal request']));
    }

    public function test_starts_with_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'code', 'operator' => 'starts_with', 'value' => 'VIP-']],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['code' => 'VIP-12345']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['code' => 'REG-12345']));
    }

    public function test_ends_with_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'email', 'operator' => 'ends_with', 'value' => '@company.com']],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['email' => 'user@company.com']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['email' => 'user@external.com']));
    }

    public function test_is_null_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'approved_by', 'operator' => 'is_null', 'value' => null]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['approved_by' => null]));
        $this->assertTrue($this->evaluator->evaluate($conditions, [])); // Field not present
        $this->assertFalse($this->evaluator->evaluate($conditions, ['approved_by' => 1]));
    }

    public function test_is_not_null_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'manager_id', 'operator' => 'is_not_null', 'value' => null]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['manager_id' => 5]));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['manager_id' => null]));
    }

    public function test_between_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'amount', 'operator' => 'between', 'value' => [10000, 50000]]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 25000]));
        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 10000])); // Inclusive
        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 50000])); // Inclusive
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => 9999]));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => 50001]));
    }

    public function test_regex_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'reference', 'operator' => 'regex', 'value' => '^TXN-\\d{6}$']],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['reference' => 'TXN-123456']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['reference' => 'TXN-12345']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['reference' => 'REF-123456']));
    }

    // =========================================================================
    // Mode Tests
    // =========================================================================

    public function test_all_mode_requires_all_rules_to_match(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [
                ['field' => 'amount', 'operator' => '>=', 'value' => 50000],
                ['field' => 'region', 'operator' => '=', 'value' => 'US'],
            ],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 75000, 'region' => 'US']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => 75000, 'region' => 'EU']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => 40000, 'region' => 'US']));
    }

    public function test_any_mode_requires_at_least_one_rule_to_match(): void
    {
        $conditions = [
            'mode' => 'any',
            'rules' => [
                ['field' => 'amount', 'operator' => '>=', 'value' => 100000],
                ['field' => 'priority', 'operator' => '=', 'value' => 'urgent'],
            ],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 150000, 'priority' => 'normal']));
        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 5000, 'priority' => 'urgent']));
        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => 150000, 'priority' => 'urgent']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => 5000, 'priority' => 'normal']));
    }

    // =========================================================================
    // Nested Groups
    // =========================================================================

    public function test_nested_condition_groups(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [
                ['field' => 'amount', 'operator' => '>=', 'value' => 25000],
            ],
            'groups' => [
                [
                    'mode' => 'any',
                    'rules' => [
                        ['field' => 'category', 'operator' => '=', 'value' => 'international'],
                        ['field' => 'region', 'operator' => 'in', 'value' => ['APAC', 'EMEA']],
                    ],
                ],
            ],
        ];

        // Matches: amount >= 25000 AND (category = international OR region in [APAC, EMEA])
        $this->assertTrue($this->evaluator->evaluate($conditions, [
            'amount' => 30000,
            'category' => 'international',
            'region' => 'US',
        ]));
        $this->assertTrue($this->evaluator->evaluate($conditions, [
            'amount' => 30000,
            'category' => 'domestic',
            'region' => 'APAC',
        ]));
        $this->assertFalse($this->evaluator->evaluate($conditions, [
            'amount' => 30000,
            'category' => 'domestic',
            'region' => 'US',
        ]));
        $this->assertFalse($this->evaluator->evaluate($conditions, [
            'amount' => 20000, // Below threshold
            'category' => 'international',
        ]));
    }

    // =========================================================================
    // Dot Notation
    // =========================================================================

    public function test_supports_dot_notation_for_nested_fields(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [
                ['field' => 'customer.tier', 'operator' => '=', 'value' => 'premium'],
                ['field' => 'transaction.amount', 'operator' => '>=', 'value' => 10000],
            ],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, [
            'customer' => ['tier' => 'premium', 'name' => 'ACME'],
            'transaction' => ['amount' => 15000],
        ]));
        $this->assertFalse($this->evaluator->evaluate($conditions, [
            'customer' => ['tier' => 'standard'],
            'transaction' => ['amount' => 15000],
        ]));
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_null_conditions_always_match(): void
    {
        $this->assertTrue($this->evaluator->evaluate(null, ['any' => 'payload']));
    }

    public function test_empty_conditions_always_match(): void
    {
        $this->assertTrue($this->evaluator->evaluate([], ['any' => 'payload']));
    }

    public function test_empty_rules_always_match(): void
    {
        $this->assertTrue($this->evaluator->evaluate(['mode' => 'all', 'rules' => []], ['any' => 'payload']));
    }

    public function test_missing_field_returns_null_for_comparison(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'missing_field', 'operator' => '=', 'value' => 'test']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['other_field' => 'value']));
    }

    public function test_throws_exception_for_unsupported_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported operator: invalid_op');

        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'test', 'operator' => 'invalid_op', 'value' => 'x']],
        ];

        $this->evaluator->evaluate($conditions, ['test' => 'value']);
    }

    public function test_throws_exception_for_missing_field_in_rule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule must have a "field" property');

        $conditions = [
            'mode' => 'all',
            'rules' => [['operator' => '=', 'value' => 'test']],
        ];

        $this->evaluator->evaluate($conditions, ['test' => 'value']);
    }

    // =========================================================================
    // Validation Tests
    // =========================================================================

    public function test_validate_returns_empty_array_for_valid_conditions(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [
                ['field' => 'amount', 'operator' => '>=', 'value' => 1000],
                ['field' => 'status', 'operator' => 'in', 'value' => ['active', 'pending']],
            ],
        ];

        $errors = $this->evaluator->validate($conditions);
        $this->assertEmpty($errors);
    }

    public function test_validate_returns_errors_for_invalid_mode(): void
    {
        $conditions = ['mode' => 'invalid'];
        $errors = $this->evaluator->validate($conditions);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid mode', $errors[0]);
    }

    public function test_validate_returns_errors_for_missing_field(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['operator' => '=', 'value' => 'test']],
        ];

        $errors = $this->evaluator->validate($conditions);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("'field' is required", $errors[0]);
    }

    public function test_validate_returns_errors_for_invalid_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'test', 'operator' => 'bad_op', 'value' => 'x']],
        ];

        $errors = $this->evaluator->validate($conditions);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("Unsupported operator 'bad_op'", $errors[0]);
    }

    public function test_validate_returns_errors_for_in_operator_without_array(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'status', 'operator' => 'in', 'value' => 'not_an_array']],
        ];

        $errors = $this->evaluator->validate($conditions);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('requires an array value', $errors[0]);
    }

    public function test_validate_returns_errors_for_between_without_two_values(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'amount', 'operator' => 'between', 'value' => [100]]],
        ];

        $errors = $this->evaluator->validate($conditions);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('exactly 2 values', $errors[0]);
    }

    public function test_validate_null_conditions_returns_empty_errors(): void
    {
        $errors = $this->evaluator->validate(null);
        $this->assertEmpty($errors);
    }

    // =========================================================================
    // Numeric Type Coercion
    // =========================================================================

    public function test_numeric_string_comparison(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'amount', 'operator' => '>=', 'value' => 50000]],
        ];

        // String numeric value should work
        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => '75000']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['amount' => '40000']));
    }

    public function test_default_operator_is_equals(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'status', 'value' => 'active']], // No operator specified
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['status' => 'active']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['status' => 'inactive']));
    }

    public function test_get_supported_operators(): void
    {
        $operators = ConditionEvaluator::getSupportedOperators();

        $this->assertContains('=', $operators);
        $this->assertContains('>=', $operators);
        $this->assertContains('in', $operators);
        $this->assertContains('between', $operators);
        $this->assertContains('regex', $operators);
    }

    // =========================================================================
    // Phase 2: Edge Case Tests
    // =========================================================================

    // --- Empty condition sets ---

    public function test_empty_conditions_array_evaluates_to_true(): void
    {
        $this->assertTrue($this->evaluator->evaluate([], ['key' => 'value']));
    }

    public function test_conditions_with_empty_rules_and_empty_groups(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [],
            'groups' => [],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['key' => 'value']));
    }

    public function test_conditions_with_only_empty_groups(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [],
            'groups' => [
                ['mode' => 'all', 'rules' => []],
            ],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['key' => 'value']));
    }

    // --- Deeply nested groups (5+ levels) ---

    public function test_deeply_nested_groups_five_levels(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [],
            'groups' => [
                [
                    'mode' => 'all',
                    'rules' => [],
                    'groups' => [
                        [
                            'mode' => 'all',
                            'rules' => [],
                            'groups' => [
                                [
                                    'mode' => 'all',
                                    'rules' => [],
                                    'groups' => [
                                        [
                                            'mode' => 'all',
                                            'rules' => [
                                                ['field' => 'deep_value', 'operator' => '=', 'value' => 'found'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['deep_value' => 'found']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['deep_value' => 'not_found']));
    }

    public function test_deeply_nested_groups_with_mixed_modes(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'level0', 'operator' => '=', 'value' => true]],
            'groups' => [
                [
                    'mode' => 'any',
                    'rules' => [['field' => 'level1_a', 'operator' => '=', 'value' => true]],
                    'groups' => [
                        [
                            'mode' => 'all',
                            'rules' => [['field' => 'level2', 'operator' => '=', 'value' => true]],
                            'groups' => [
                                [
                                    'mode' => 'any',
                                    'rules' => [
                                        ['field' => 'level3_a', 'operator' => '=', 'value' => true],
                                        ['field' => 'level3_b', 'operator' => '=', 'value' => true],
                                    ],
                                    'groups' => [
                                        [
                                            'mode' => 'all',
                                            'rules' => [['field' => 'level4', 'operator' => '=', 'value' => true]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // All conditions met through level3_a
        $this->assertTrue($this->evaluator->evaluate($conditions, [
            'level0' => true,
            'level1_a' => false,
            'level2' => true,
            'level3_a' => true,
            'level3_b' => false,
            'level4' => false,
        ]));

        // level0 fails
        $this->assertFalse($this->evaluator->evaluate($conditions, [
            'level0' => false,
            'level1_a' => true,
            'level2' => true,
            'level3_a' => true,
        ]));
    }

    // --- Invalid operator handling ---

    public function test_invalid_operator_throws_exception_with_message(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'x', 'operator' => 'LIKE', 'value' => '%test%']],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported operator: LIKE');
        $this->evaluator->evaluate($conditions, ['x' => 'test']);
    }

    public function test_empty_string_operator_uses_default_equals(): void
    {
        // When operator is explicitly empty string, it should still use default
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'x', 'operator' => '', 'value' => 'test']],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->evaluator->evaluate($conditions, ['x' => 'test']);
    }

    // --- Null/missing payload fields ---

    public function test_null_field_value_with_equals_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'missing', 'operator' => '=', 'value' => 'something']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, []));
    }

    public function test_null_field_value_with_greater_than(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'missing', 'operator' => '>', 'value' => 0]],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, []));
    }

    public function test_null_field_value_with_in_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'missing', 'operator' => 'in', 'value' => ['a', 'b']]],
        ];

        // null is not in the array (strict)
        $this->assertFalse($this->evaluator->evaluate($conditions, []));
    }

    public function test_null_field_value_with_contains_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'missing', 'operator' => 'contains', 'value' => 'test']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, []));
    }

    public function test_null_field_value_with_between_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'missing', 'operator' => 'between', 'value' => [1, 10]]],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, []));
    }

    public function test_null_field_value_with_regex_operator(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'missing', 'operator' => 'regex', 'value' => '.*']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, []));
    }

    public function test_explicit_null_field_with_is_null(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'field', 'operator' => 'is_null', 'value' => null]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['field' => null]));
    }

    public function test_explicit_null_field_with_is_not_null(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'field', 'operator' => 'is_not_null', 'value' => null]],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['field' => null]));
    }

    // --- Type coercion edge cases ---

    public function test_string_zero_equals_int_zero(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'val', 'operator' => '=', 'value' => 0]],
        ];

        // "0" is numeric, so (float)"0" === (float)0
        $this->assertTrue($this->evaluator->evaluate($conditions, ['val' => '0']));
    }

    public function test_string_zero_not_equals_empty_string(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'val', 'operator' => '=', 'value' => '']],
        ];

        // "0" !== ""
        $this->assertFalse($this->evaluator->evaluate($conditions, ['val' => '0']));
    }

    public function test_float_string_comparison(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'amount', 'operator' => '=', 'value' => 99.99]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['amount' => '99.99']));
    }

    public function test_boolean_true_not_equal_string_true(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'flag', 'operator' => '=', 'value' => 'true']],
        ];

        // true !== "true" (non-numeric, strict comparison)
        $this->assertFalse($this->evaluator->evaluate($conditions, ['flag' => true]));
    }

    public function test_int_one_in_array_with_loose_comparison(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'val', 'operator' => 'in', 'value' => [1, 2, 3]]],
        ];

        // "1" should be found via loose comparison
        $this->assertTrue($this->evaluator->evaluate($conditions, ['val' => '1']));
    }

    public function test_negative_number_comparison(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'balance', 'operator' => '<', 'value' => 0]],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['balance' => -100]));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['balance' => 0]));
    }

    // --- Malformed/malicious regex inputs (ReDoS patterns) ---

    public function test_redos_pattern_does_not_hang(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'input', 'operator' => 'regex', 'value' => '(a+)+$']],
        ];

        // This is a classic ReDoS pattern. With backtrack limit set to 10000,
        // it should return false rather than hanging
        $result = $this->evaluator->evaluate($conditions, ['input' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!']);
        $this->assertFalse($result);
    }

    public function test_complex_redos_pattern(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'input', 'operator' => 'regex', 'value' => '(a|aa)+$']],
        ];

        $result = $this->evaluator->evaluate($conditions, ['input' => str_repeat('a', 30).'!']);
        $this->assertFalse($result);
    }

    // --- Empty regex pattern ---

    public function test_empty_regex_pattern_returns_false(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'input', 'operator' => 'regex', 'value' => '']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['input' => 'anything']));
    }

    // --- Invalid regex compilation ---

    public function test_invalid_regex_pattern_returns_false(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'input', 'operator' => 'regex', 'value' => '[invalid']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['input' => 'test']));
    }

    public function test_regex_with_unmatched_parenthesis(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'input', 'operator' => 'regex', 'value' => '(unclosed']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['input' => 'unclosed']));
    }

    public function test_regex_with_invalid_quantifier(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'input', 'operator' => 'regex', 'value' => '*invalid']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['input' => 'test']));
    }

    public function test_valid_regex_pattern_matches(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'email', 'operator' => 'regex', 'value' => '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$']],
        ];

        $this->assertTrue($this->evaluator->evaluate($conditions, ['email' => 'test@example.com']));
        $this->assertFalse($this->evaluator->evaluate($conditions, ['email' => 'not-an-email']));
    }

    // --- Validation edge cases for regex ---

    public function test_validate_empty_regex_pattern_returns_error(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'test', 'operator' => 'regex', 'value' => '']],
        ];

        $errors = $this->evaluator->validate($conditions);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('non-empty string pattern', $errors[0]);
    }

    public function test_validate_non_string_regex_pattern_returns_error(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'test', 'operator' => 'regex', 'value' => 123]],
        ];

        $errors = $this->evaluator->validate($conditions);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('non-empty string pattern', $errors[0]);
    }

    public function test_validate_invalid_regex_returns_error(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'test', 'operator' => 'regex', 'value' => '[invalid']],
        ];

        $errors = $this->evaluator->validate($conditions);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid regex pattern', $errors[0]);
    }

    // --- Non-string values with string operators ---

    public function test_contains_with_non_string_field_returns_false(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'val', 'operator' => 'contains', 'value' => 'test']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['val' => 123]));
    }

    public function test_starts_with_non_string_field_returns_false(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'val', 'operator' => 'starts_with', 'value' => 'test']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['val' => 123]));
    }

    public function test_ends_with_non_string_field_returns_false(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'val', 'operator' => 'ends_with', 'value' => 'test']],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['val' => 123]));
    }

    public function test_between_with_non_numeric_field_returns_false(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'val', 'operator' => 'between', 'value' => [1, 10]]],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['val' => 'not_a_number']));
    }

    public function test_between_with_non_numeric_bounds_returns_false(): void
    {
        $conditions = [
            'mode' => 'all',
            'rules' => [['field' => 'val', 'operator' => 'between', 'value' => ['a', 'z']]],
        ];

        $this->assertFalse($this->evaluator->evaluate($conditions, ['val' => 5]));
    }
}
