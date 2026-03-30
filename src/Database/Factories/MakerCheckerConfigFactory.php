<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerConfig;

/**
 * @extends Factory<MakerCheckerConfig>
 */
class MakerCheckerConfigFactory extends Factory
{
    protected $model = MakerCheckerConfig::class;

    public function definition(): array
    {
        return [
            'configurable_type' => 'App\\Models\\User',
            'action' => RequestType::CREATE->value,
            'approvals' => ['roles' => ['admin' => 1]],
            'unique_fields' => [],
            'conditions' => null,
            'priority' => 0,
            'is_active' => true,
            'team_id' => null,
            'metadata' => null,
            'description' => $this->faker->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn(array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function forAction(RequestType $action): static
    {
        return $this->state(fn(array $attributes): array => [
            'action' => $action->value,
        ]);
    }

    public function forModel(string $modelClass): static
    {
        return $this->state(fn(array $attributes): array => [
            'configurable_type' => $modelClass,
        ]);
    }

    public function forAllActions(): static
    {
        return $this->state(fn(array $attributes): array => [
            'action' => null,
        ]);
    }

    public function forTeam(int $teamId): static
    {
        return $this->state(fn(array $attributes): array => [
            'team_id' => $teamId,
        ]);
    }

    public function withPriority(int $priority): static
    {
        return $this->state(fn(array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public function withConditions(array $conditions): static
    {
        return $this->state(fn(array $attributes): array => [
            'conditions' => $conditions,
        ]);
    }
}
