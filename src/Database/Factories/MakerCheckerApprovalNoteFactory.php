<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Moffhub\MakerChecker\Models\MakerCheckerApprovalNote;

/**
 * @extends Factory<MakerCheckerApprovalNote>
 */
class MakerCheckerApprovalNoteFactory extends Factory
{
    protected $model = MakerCheckerApprovalNote::class;

    public function definition(): array
    {
        return [
            'request_id' => null,
            'user_type' => 'App\\Models\\User',
            'user_id' => $this->faker->randomNumber(),
            'action' => 'approved',
            'note' => $this->faker->sentence(),
        ];
    }

    public function forApproval(): static
    {
        return $this->state(fn(array $attributes): array => [
            'action' => 'approved',
        ]);
    }

    public function forRejection(): static
    {
        return $this->state(fn(array $attributes): array => [
            'action' => 'rejected',
        ]);
    }
}
