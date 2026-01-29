<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Services;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Evaluates conditional rules against a payload.
 *
 * Supports complex conditions with multiple rules, logical operators (AND/OR),
 * and nested condition groups.
 *
 * Condition format:
 * {
 *     "mode": "all",  // "all" (AND) or "any" (OR)
 *     "rules": [
 *         {"field": "amount", "operator": ">=", "value": 50000},
 *         {"field": "region", "operator": "in", "value": ["US", "EU"]}
 *     ],
 *     "groups": [  // Optional nested groups
 *         {"mode": "any", "rules": [...]}
 *     ]
 * }
 */
class ConditionEvaluator
{
    /**
     * Supported operators.
     *
     * @var array<string>
     */
    private const OPERATORS = [
        '=', '!=', '>', '>=', '<', '<=',
        'in', 'not_in', 'contains', 'starts_with', 'ends_with',
        'is_null', 'is_not_null', 'between', 'regex',
    ];

    /**
     * Evaluate conditions against a payload.
     *
     * @param  array<string, mixed>|null  $conditions  The conditions configuration
     * @param  array<string, mixed>  $payload  The request payload to evaluate against
     * @return bool True if conditions match (or if conditions are null/empty)
     */
    public function evaluate(?array $conditions, array $payload): bool
    {
        // No conditions means always match (default/fallback config)
        if ($conditions === null || $conditions === []) {
            return true;
        }

        $mode = $conditions['mode'] ?? 'all';
        $rules = $conditions['rules'] ?? [];
        $groups = $conditions['groups'] ?? [];

        // Evaluate all rules
        $results = [];

        foreach ($rules as $rule) {
            $results[] = $this->evaluateRule($rule, $payload);
        }

        // Evaluate nested groups recursively
        foreach ($groups as $group) {
            $results[] = $this->evaluate($group, $payload);
        }

        // Empty rules/groups means match
        if ($results === []) {
            return true;
        }

        // Apply logical operator
        return $mode === 'all'
            ? !in_array(false, $results, true)  // AND: all must be true
            : in_array(true, $results, true);    // OR: at least one true
    }

    /**
     * Evaluate a single rule against the payload.
     *
     * @param  array<string, mixed>  $rule  The rule configuration
     * @param  array<string, mixed>  $payload  The payload to evaluate
     * @return bool True if the rule matches
     *
     * @throws InvalidArgumentException If operator is not supported
     */
    public function evaluateRule(array $rule, array $payload): bool
    {
        $field = $rule['field'] ?? throw new InvalidArgumentException('Rule must have a "field" property');
        $operator = $rule['operator'] ?? '=';
        $compareValue = $rule['value'] ?? null;

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported operator: {$operator}");
        }

        // Get the field value from payload (supports dot notation)
        $fieldValue = Arr::get($payload, $field);

        return match ($operator) {
            '=' => $this->equals($fieldValue, $compareValue),
            '!=' => !$this->equals($fieldValue, $compareValue),
            '>' => $this->greaterThan($fieldValue, $compareValue),
            '>=' => $this->greaterThanOrEqual($fieldValue, $compareValue),
            '<' => $this->lessThan($fieldValue, $compareValue),
            '<=' => $this->lessThanOrEqual($fieldValue, $compareValue),
            'in' => $this->in($fieldValue, $compareValue),
            'not_in' => !$this->in($fieldValue, $compareValue),
            'contains' => $this->contains($fieldValue, $compareValue),
            'starts_with' => $this->startsWith($fieldValue, $compareValue),
            'ends_with' => $this->endsWith($fieldValue, $compareValue),
            'is_null' => $fieldValue === null,
            'is_not_null' => $fieldValue !== null,
            'between' => $this->between($fieldValue, $compareValue),
            default => $this->matchesRegex($fieldValue, $compareValue), // 'regex'
        };
    }

    /**
     * Validate that a conditions array is well-formed.
     *
     * @param  array<string, mixed>|null  $conditions
     * @return array<string> Array of validation errors (empty if valid)
     */
    public function validate(?array $conditions): array
    {
        $errors = [];

        if ($conditions === null) {
            return $errors;
        }

        // Validate mode
        $mode = $conditions['mode'] ?? 'all';
        if (!in_array($mode, ['all', 'any'], true)) {
            $errors[] = "Invalid mode: '{$mode}'. Must be 'all' or 'any'.";
        }

        // Validate rules
        $rules = $conditions['rules'] ?? [];
        foreach ($rules as $index => $rule) {
            $ruleErrors = $this->validateRule($rule, $index);
            $errors = array_merge($errors, $ruleErrors);
        }

        // Validate nested groups
        $groups = $conditions['groups'] ?? [];
        foreach ($groups as $index => $group) {
            $groupErrors = $this->validate($group);
            foreach ($groupErrors as $error) {
                $errors[] = "Group {$index}: {$error}";
            }
        }

        return $errors;
    }

    /**
     * Validate a single rule.
     *
     * @param  array<string, mixed>  $rule
     * @return array<string>
     */
    private function validateRule(array $rule, int $index): array
    {
        $errors = [];

        if (!isset($rule['field']) || !is_string($rule['field']) || $rule['field'] === '') {
            $errors[] = "Rule {$index}: 'field' is required and must be a non-empty string.";
        }

        $operator = $rule['operator'] ?? '=';
        if (!in_array($operator, self::OPERATORS, true)) {
            $errors[] = "Rule {$index}: Unsupported operator '{$operator}'. Supported: ".implode(', ', self::OPERATORS);
        }

        // Validate value based on operator
        if (in_array($operator, ['in', 'not_in'], true) && !is_array($rule['value'] ?? null)) {
            $errors[] = "Rule {$index}: Operator '{$operator}' requires an array value.";
        }

        if ($operator === 'between') {
            $value = $rule['value'] ?? null;
            if (!is_array($value) || count($value) !== 2) {
                $errors[] = "Rule {$index}: Operator 'between' requires an array with exactly 2 values.";
            }
        }

        return $errors;
    }

    /**
     * Get the list of supported operators.
     *
     * @return array<string>
     */
    public static function getSupportedOperators(): array
    {
        return self::OPERATORS;
    }

    // =========================================================================
    // Comparison Methods
    // =========================================================================

    private function equals(mixed $fieldValue, mixed $compareValue): bool
    {
        // Handle type coercion for numeric comparisons
        if (is_numeric($fieldValue) && is_numeric($compareValue)) {
            return (float) $fieldValue === (float) $compareValue;
        }

        return $fieldValue === $compareValue;
    }

    private function greaterThan(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_numeric($fieldValue) || !is_numeric($compareValue)) {
            return false;
        }

        return (float) $fieldValue > (float) $compareValue;
    }

    private function greaterThanOrEqual(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_numeric($fieldValue) || !is_numeric($compareValue)) {
            return false;
        }

        return (float) $fieldValue >= (float) $compareValue;
    }

    private function lessThan(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_numeric($fieldValue) || !is_numeric($compareValue)) {
            return false;
        }

        return (float) $fieldValue < (float) $compareValue;
    }

    private function lessThanOrEqual(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_numeric($fieldValue) || !is_numeric($compareValue)) {
            return false;
        }

        return (float) $fieldValue <= (float) $compareValue;
    }

    private function in(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_array($compareValue)) {
            return false;
        }

        return in_array($fieldValue, $compareValue, false); // Loose comparison for flexibility
    }

    private function contains(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_string($fieldValue) || !is_string($compareValue)) {
            return false;
        }

        return str_contains($fieldValue, $compareValue);
    }

    private function startsWith(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_string($fieldValue) || !is_string($compareValue)) {
            return false;
        }

        return str_starts_with($fieldValue, $compareValue);
    }

    private function endsWith(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_string($fieldValue) || !is_string($compareValue)) {
            return false;
        }

        return str_ends_with($fieldValue, $compareValue);
    }

    private function between(mixed $fieldValue, mixed $compareValue): bool
    {
        if (!is_numeric($fieldValue) || !is_array($compareValue) || count($compareValue) !== 2) {
            return false;
        }

        [$min, $max] = $compareValue;
        if (!is_numeric($min) || !is_numeric($max)) {
            return false;
        }

        $value = (float) $fieldValue;

        return $value >= (float) $min && $value <= (float) $max;
    }

    private function matchesRegex(mixed $fieldValue, mixed $pattern): bool
    {
        if (!is_string($fieldValue) || !is_string($pattern)) {
            return false;
        }

        // Suppress errors from invalid regex patterns
        return @preg_match("/{$pattern}/", $fieldValue) === 1;
    }
}
