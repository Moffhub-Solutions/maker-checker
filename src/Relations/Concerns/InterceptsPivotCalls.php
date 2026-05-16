<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Overrides the pivot mutation methods on a BelongsToMany/MorphToMany so
 * they are routed through maker-checker when the parent model has opted the
 * relation in. Combine with ResolvesRelationInterception in the proxy class.
 */
trait InterceptsPivotCalls
{
    public function attach($id, array $attributes = [], $touch = true): void
    {
        // attach() is void in Eloquent; interception happens via side effects
        // (a pending request is created and recorded on the model class).
        $this->interceptRelation(
            'attach',
            fn() => parent::attach($id, $attributes, $touch),
            $id,
            $attributes,
            true,
            $touch,
        );
    }

    public function detach($ids = null, $touch = true)
    {
        return $this->interceptRelation(
            'detach',
            fn() => parent::detach($ids, $touch),
            $ids,
            [],
            true,
            $touch,
        );
    }

    public function sync($ids, $detaching = true)
    {
        return $this->interceptRelation(
            'sync',
            fn() => parent::sync($ids, $detaching),
            $ids,
            [],
            $detaching,
        );
    }

    public function syncWithoutDetaching($ids)
    {
        return $this->interceptRelation(
            'syncWithoutDetaching',
            fn() => parent::syncWithoutDetaching($ids),
            $ids,
        );
    }

    public function toggle($ids, $touch = true)
    {
        return $this->interceptRelation(
            'toggle',
            fn() => parent::toggle($ids, $touch),
            $ids,
            [],
            true,
            $touch,
        );
    }

    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        return $this->interceptRelation(
            'updateExistingPivot',
            fn() => parent::updateExistingPivot($id, $attributes, $touch),
            $id,
            $attributes,
            true,
            $touch,
        );
    }

    protected function interceptionParent(): Model
    {
        return $this->getParent();
    }
}
