<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Relations\PendingRelationship;

/**
 * Shared decision logic for the approvable relation proxies.
 *
 * Decides, per call, whether a relationship mutation should proceed natively
 * or be captured as a pending maker-checker request.
 */
trait ResolvesRelationInterception
{
    /**
     * The parent/child model the relation is declared on.
     */
    abstract protected function interceptionParent(): Model;

    /**
     * The relationship method name (e.g. 'compensations').
     */
    abstract public function getRelationName();

    protected function interceptRelation(
        string $operation,
        Closure $proceed,
        mixed $ids = null,
        array $attributes = [],
        bool $detaching = true,
        bool $touch = true,
    ): mixed {
        $parent = $this->interceptionParent();
        $modelClass = $parent::class;
        $relation = $this->getRelationName();

        // Relation not opted in for this operation: behave natively.
        if (!method_exists($modelClass, 'relationRequiresApproval')
            || !$modelClass::relationRequiresApproval($relation, $operation)) {
            return $proceed();
        }

        // Explicit one-time / scoped bypass (withoutApproval, seeders, etc).
        if (method_exists($modelClass, 'approvalBypassActive') && $modelClass::approvalBypassActive()) {
            return $proceed();
        }

        // No maker (migrations, seeders, unauthenticated jobs): proceed.
        $maker = method_exists($modelClass, 'getApprovalMaker')
            ? $modelClass::getApprovalMaker()
            : null;

        if (!$maker instanceof Model) {
            return $proceed();
        }

        $request = $this->buildPendingRelationship()
            ->madeBy($maker)
            ->dispatch($operation, $ids, $attributes, $detaching, $touch);

        if (method_exists($modelClass, 'handleRelationIntercept')) {
            return $modelClass::handleRelationIntercept(
                $request,
                ucfirst($operation)." on '{$relation}' requires approval."
            );
        }

        return false;
    }

    protected function buildPendingRelationship(): PendingRelationship
    {
        return new PendingRelationship($this->interceptionParent(), $this->getRelationName());
    }
}
