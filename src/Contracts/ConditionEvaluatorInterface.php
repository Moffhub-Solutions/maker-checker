<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

/**
 * Contract for evaluating conditional rules against a payload.
 *
 * Supports complex conditions with multiple rules, logical operators (AND/OR),
 * and nested condition groups.
 */
interface ConditionEvaluatorInterface
{
    /**
     * Evaluate conditions against a payload.
     *
     * @param  array<string, mixed>|null  $conditions  The conditions configuration
     * @param  array<string, mixed>  $payload  The request payload to evaluate against
     * @return bool True if conditions match (or if conditions are null/empty)
     */
    public function evaluate(?array $conditions, array $payload): bool;

    /**
     * Evaluate a single rule against the payload.
     *
     * @param  array<string, mixed>  $rule  The rule configuration
     * @param  array<string, mixed>  $payload  The payload to evaluate
     * @return bool True if the rule matches
     */
    public function evaluateRule(array $rule, array $payload): bool;

    /**
     * Validate that a conditions array is well-formed.
     *
     * @param  array<string, mixed>|null  $conditions
     * @return array<string> Array of validation errors (empty if valid)
     */
    public function validate(?array $conditions): array;
}
