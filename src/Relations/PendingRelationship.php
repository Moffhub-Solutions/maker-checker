<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use RuntimeException;

/**
 * Fluent entry point for routing a relationship change through maker-checker.
 *
 * Returned by RequiresApproval::requestRelation(). Each terminal method
 * (attach, detach, sync, ...) builds and persists a pending request and
 * returns the MakerCheckerRequest instead of mutating the relationship.
 *
 * ```php
 * $employee->requestRelation('compensations')
 *          ->madeBy($actingUser)
 *          ->attach($compensation);
 * ```
 */
class PendingRelationship
{
    private ?Model $maker = null;

    private ?string $description = null;

    /** @var array<string, mixed> */
    private array $approvals = [];

    private ?int $teamId = null;

    public function __construct(
        private readonly Model $parent,
        private readonly string $relation,
    ) {}

    /**
     * Set the user making the request. Defaults to the parent model's
     * configured approval maker, then the authenticated user.
     */
    public function madeBy(Model $maker): self
    {
        $this->maker = $maker;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Override the approval requirements for this request.
     *
     * @param  array<string, mixed>  $approvals
     */
    public function withApprovals(array $approvals): self
    {
        $this->approvals = $approvals;

        return $this;
    }

    public function forTeam(?int $teamId): self
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function attach(mixed $ids, array $attributes = [], bool $touch = true): MakerCheckerRequest
    {
        return $this->dispatch('attach', $ids, $attributes, true, $touch);
    }

    public function detach(mixed $ids = null, bool $touch = true): MakerCheckerRequest
    {
        return $this->dispatch('detach', $ids, [], true, $touch);
    }

    public function sync(mixed $ids, bool $detaching = true): MakerCheckerRequest
    {
        return $this->dispatch('sync', $ids, [], $detaching);
    }

    public function syncWithoutDetaching(mixed $ids): MakerCheckerRequest
    {
        return $this->dispatch('syncWithoutDetaching', $ids);
    }

    public function toggle(mixed $ids, bool $touch = true): MakerCheckerRequest
    {
        return $this->dispatch('toggle', $ids, [], true, $touch);
    }

    public function updateExistingPivot(mixed $id, array $attributes, bool $touch = true): MakerCheckerRequest
    {
        return $this->dispatch('updateExistingPivot', $id, $attributes, true, $touch);
    }

    public function associate(Model $related): MakerCheckerRequest
    {
        return $this->dispatch('associate', $related);
    }

    public function dissociate(): MakerCheckerRequest
    {
        return $this->dispatch('dissociate');
    }

    /**
     * Build and persist the pending relationship request.
     */
    public function dispatch(
        string $operation,
        mixed $ids = null,
        array $attributes = [],
        bool $detaching = true,
        bool $touch = true,
    ): MakerCheckerRequest {
        $maker = $this->resolveMaker();

        $builder = MakerChecker::request()
            ->toRelation(
                $this->parent,
                $this->relation,
                $operation,
                $ids,
                $attributes,
                $this->approvals,
                $this->teamId,
                $detaching,
                $touch,
            )
            ->madeBy($maker);

        if ($this->description !== null) {
            $builder->description($this->description);
        }

        return $builder->save();
    }

    private function resolveMaker(): Model
    {
        if ($this->maker instanceof Model) {
            return $this->maker;
        }

        $parentClass = $this->parent::class;

        if (method_exists($parentClass, 'getApprovalMaker')) {
            $maker = $parentClass::getApprovalMaker();
            if ($maker instanceof Model) {
                return $maker;
            }
        }

        $user = auth()->user();
        if ($user instanceof Model) {
            return $user;
        }

        throw new RuntimeException(
            'No maker could be resolved for the relationship request. '
            .'Call ->madeBy($user), set an approval maker on the model, or authenticate a user.'
        );
    }
}
