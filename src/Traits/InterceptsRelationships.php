<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Relations\ApprovableBelongsTo;
use Moffhub\MakerChecker\Relations\ApprovableBelongsToMany;
use Moffhub\MakerChecker\Relations\ApprovableHasMany;
use Moffhub\MakerChecker\Relations\ApprovableHasOne;
use Moffhub\MakerChecker\Relations\ApprovableMorphMany;
use Moffhub\MakerChecker\Relations\ApprovableMorphOne;
use Moffhub\MakerChecker\Relations\ApprovableMorphToMany;

/**
 * Transparently routes native relationship writes through maker-checker.
 *
 * Native pivot/foreign-key writes (attach, detach, sync, toggle,
 * updateExistingPivot, associate, dissociate) and child writes through
 * hasOne/hasMany/morphOne/morphMany (save, saveMany, create, createMany)
 * bypass Eloquent model events,
 * so RequiresApproval cannot see them. This trait swaps the relation objects
 * for approvable proxies on the relations you opt in.
 *
 * Requires the model to also use {@see RequiresApproval} (for maker
 * resolution, bypass handling and intercepted-request state).
 *
 * ```php
 * class Employee extends Model
 * {
 *     use RequiresApproval, InterceptsRelationships;
 *
 *     // All operations on these relations require approval:
 *     protected static array $approvableRelations = ['compensations'];
 *
 *     // Or restrict per relation:
 *     // protected static array $approvableRelations = [
 *     //     'compensations' => ['attach', 'detach', 'sync'],
 *     //     'manager'       => ['associate', 'dissociate'],
 *     // ];
 *
 *     public function compensations(): BelongsToMany { ... }
 * }
 *
 * // Native syntax is now intercepted. Relationship methods have varying
 * // return types (attach() is void), so detect interception via
 * // wasIntercepted()/getInterceptedRequest(), not a return value.
 * $employee->compensations()->attach($compensation);
 *
 * if ($employee::wasIntercepted()) {
 *     $request = $employee::getInterceptedRequest();
 * }
 *
 * // Bypass when needed:
 * Employee::withoutApprovalDo(fn () => $employee->compensations()->attach($c));
 * ```
 */
trait InterceptsRelationships
{
    /**
     * Whether the given relation+operation must go through approval,
     * based on the model's $approvableRelations configuration.
     */
    public static function relationRequiresApproval(string $relation, string $operation): bool
    {
        $config = static::approvableRelationConfig();

        if (!array_key_exists($relation, $config)) {
            return false;
        }

        $allowed = $config[$relation];

        // true means every supported operation on this relation.
        if ($allowed === true) {
            return true;
        }

        return in_array($operation, $allowed, true);
    }

    /**
     * Whether any operation on the relation is approvable (used to decide
     * if the relation object itself needs to be a proxy).
     */
    public static function relationIsApprovable(?string $relation): bool
    {
        if ($relation === null) {
            return false;
        }

        return array_key_exists($relation, static::approvableRelationConfig());
    }

    /**
     * Normalize $approvableRelations into [relation => true|array<string>].
     *
     * Accepts a plain list (['comps', 'tags']) or a map
     * (['comps' => ['attach'], 'tags' => true]).
     *
     * @return array<string, true|array<string>>
     */
    protected static function approvableRelationConfig(): array
    {
        if (!property_exists(static::class, 'approvableRelations')) {
            return [];
        }

        /** @var array<int|string, mixed> $raw */
        $raw = static::$approvableRelations;
        $normalized = [];

        foreach ($raw as $key => $value) {
            if (is_int($key)) {
                // List form: value is the relation name, all operations.
                $normalized[(string) $value] = true;
            } elseif ($value === true || $value === '*' || $value === ['*']) {
                // Map form, all operations.
                $normalized[$key] = true;
            } else {
                // Map form, explicit list of operations.
                $normalized[$key] = (array) $value;
            }
        }

        return $normalized;
    }

    #[\Override]
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ) {
        if (static::relationIsApprovable($relationName)) {
            return new ApprovableBelongsToMany(
                $query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName
            );
        }

        return parent::newBelongsToMany(
            $query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName
        );
    }

    #[\Override]
    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        $name,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
        $inverse = false,
    ) {
        if (static::relationIsApprovable($relationName)) {
            return new ApprovableMorphToMany(
                $query, $parent, $name, $table, $foreignPivotKey, $relatedPivotKey,
                $parentKey, $relatedKey, $relationName, $inverse
            );
        }

        return parent::newMorphToMany(
            $query, $parent, $name, $table, $foreignPivotKey, $relatedPivotKey,
            $parentKey, $relatedKey, $relationName, $inverse
        );
    }

    #[\Override]
    protected function newBelongsTo(Builder $query, Model $child, $foreignKey, $ownerKey, $relation)
    {
        if (static::relationIsApprovable($relation)) {
            return new ApprovableBelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
        }

        return parent::newBelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }

    #[\Override]
    protected function newHasOne(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        $name = static::guessApprovableChildRelation();

        if (static::relationIsApprovable($name)) {
            return (new ApprovableHasOne($query, $parent, $foreignKey, $localKey))->setRelationName($name);
        }

        return parent::newHasOne($query, $parent, $foreignKey, $localKey);
    }

    #[\Override]
    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        $name = static::guessApprovableChildRelation();

        if (static::relationIsApprovable($name)) {
            return (new ApprovableHasMany($query, $parent, $foreignKey, $localKey))->setRelationName($name);
        }

        return parent::newHasMany($query, $parent, $foreignKey, $localKey);
    }

    #[\Override]
    protected function newMorphOne(Builder $query, Model $parent, $type, $id, $localKey)
    {
        $name = static::guessApprovableChildRelation();

        if (static::relationIsApprovable($name)) {
            return (new ApprovableMorphOne($query, $parent, $type, $id, $localKey))->setRelationName($name);
        }

        return parent::newMorphOne($query, $parent, $type, $id, $localKey);
    }

    #[\Override]
    protected function newMorphMany(Builder $query, Model $parent, $type, $id, $localKey)
    {
        $name = static::guessApprovableChildRelation();

        if (static::relationIsApprovable($name)) {
            return (new ApprovableMorphMany($query, $parent, $type, $id, $localKey))->setRelationName($name);
        }

        return parent::newMorphMany($query, $parent, $type, $id, $localKey);
    }

    /**
     * Name of the relationship method that is building a has-one/has-many
     * relation. Eloquent does not pass it to newHasMany() and friends, so it
     * is read from the call stack (the same technique Eloquent uses for
     * belongsToMany). Skipped entirely when no relations are opted in.
     */
    protected static function guessApprovableChildRelation(): ?string
    {
        if (static::approvableRelationConfig() === []) {
            return null;
        }

        $skip = [
            __FUNCTION__, 'newHasOne', 'newHasMany', 'newMorphOne', 'newMorphMany',
            'hasOne', 'hasMany', 'morphOne', 'morphMany',
        ];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            if (!in_array($frame['function'], $skip, true)) {
                return $frame['function'];
            }
        }

        return null;
    }
}
