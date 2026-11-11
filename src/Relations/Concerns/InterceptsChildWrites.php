<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Overrides the child write methods on a HasOne/HasMany/MorphOne/MorphMany
 * so they are routed through maker-checker when the parent model has opted
 * the relation in. Combine with ResolvesRelationInterception.
 */
trait InterceptsChildWrites
{
    public function save(Model $model)
    {
        return $this->interceptRelation('save', fn() => parent::save($model), $model);
    }

    public function saveMany($models)
    {
        return $this->interceptRelation('saveMany', fn() => parent::saveMany($models), $models);
    }

    public function create(array $attributes = [])
    {
        return $this->interceptRelation('create', fn() => parent::create($attributes), $attributes);
    }

    public function createMany(iterable $records)
    {
        return $this->interceptRelation('createMany', fn() => parent::createMany($records), $records);
    }

    protected function interceptionParent(): Model
    {
        return $this->getParent();
    }
}
