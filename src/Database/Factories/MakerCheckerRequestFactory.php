<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * @extends Factory<MakerCheckerRequest>
 */
class MakerCheckerRequestFactory extends Factory
{
    protected $model = MakerCheckerRequest::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->uuid(),
            'description' => $this->faker->sentence(),
            'type' => RequestType::CREATE,
            'status' => RequestStatus::PENDING,
            'payload' => ['name' => $this->faker->name()],
            'metadata' => null,
            'required_approvals' => null,
            'approvals' => null,
            'subject_type' => 'App\\Models\\User',
            'subject_id' => null,
            'maker_type' => 'App\\Models\\User',
            'maker_id' => $this->faker->randomNumber(),
            'checker_type' => null,
            'checker_id' => null,
            'made_at' => now(),
            'checked_at' => null,
            'remarks' => '',
            'exception' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn(array $attributes): array => [
            'status' => RequestStatus::APPROVED,
            'checker_type' => 'App\\Models\\User',
            'checker_id' => $this->faker->randomNumber(),
            'checked_at' => now(),
            'remarks' => 'Approved',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn(array $attributes): array => [
            'status' => RequestStatus::REJECTED,
            'checker_type' => 'App\\Models\\User',
            'checker_id' => $this->faker->randomNumber(),
            'checked_at' => now(),
            'remarks' => 'Rejected',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn(array $attributes): array => [
            'status' => RequestStatus::PENDING,
            'checker_type' => null,
            'checker_id' => null,
            'checked_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn(array $attributes): array => [
            'status' => RequestStatus::CANCELLED,
            'checked_at' => now(),
            'remarks' => 'Cancelled',
        ]);
    }

    public function forCreate(): static
    {
        return $this->state(fn(array $attributes): array => [
            'type' => RequestType::CREATE,
        ]);
    }

    public function forUpdate(): static
    {
        return $this->state(fn(array $attributes): array => [
            'type' => RequestType::UPDATE,
            'subject_id' => $this->faker->randomNumber(),
        ]);
    }

    public function forDelete(): static
    {
        return $this->state(fn(array $attributes): array => [
            'type' => RequestType::DELETE,
            'subject_id' => $this->faker->randomNumber(),
        ]);
    }

    public function forExecute(): static
    {
        return $this->state(fn(array $attributes): array => [
            'type' => RequestType::EXECUTE,
        ]);
    }

    /**
     * @param  array<string, int>|array{roles?: array<string, int>, users?: array<string>}  $approvals
     */
    public function withApprovals(array $approvals): static
    {
        return $this->state(fn(array $attributes): array => [
            'required_approvals' => $approvals,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): static
    {
        return $this->state(fn(array $attributes): array => [
            'payload' => $payload,
        ]);
    }
}
