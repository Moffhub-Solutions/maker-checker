<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as BaseCollection;
use InvalidArgumentException;
use Moffhub\MakerChecker\Exceptions\FulfillmentException;

/**
 * Single source of truth for everything maker-checker needs to do with an
 * Eloquent relationship change: normalising the operands so they can be
 * persisted on a request, snapshotting the relationship for rollback,
 * applying the operation on approval, and reversing it on rollback.
 *
 * Pivot operations (BelongsToMany / MorphToMany):
 *   attach, detach, sync, syncWithoutDetaching, toggle, updateExistingPivot
 *
 * Foreign-key operations (BelongsTo):
 *   associate, dissociate
 *
 * Child-write operations (HasOne / HasMany / MorphOne / MorphMany):
 *   save, saveMany, create, createMany
 */
final class RelationOperation
{
    public const PIVOT_OPERATIONS = [
        'attach',
        'detach',
        'sync',
        'syncWithoutDetaching',
        'toggle',
        'updateExistingPivot',
    ];

    public const BELONGS_TO_OPERATIONS = [
        'associate',
        'dissociate',
    ];

    public const HAS_MANY_OPERATIONS = [
        'save',
        'saveMany',
        'create',
        'createMany',
    ];

    /**
     * Assert the operation name is one the package knows how to apply.
     */
    public static function assertSupported(string $operation): void
    {
        $all = array_merge(self::PIVOT_OPERATIONS, self::BELONGS_TO_OPERATIONS, self::HAS_MANY_OPERATIONS);

        if (!in_array($operation, $all, true)) {
            throw new InvalidArgumentException(
                "Unsupported relationship operation '{$operation}'. Supported: ".implode(', ', $all)
            );
        }
    }

    public static function isPivotOperation(string $operation): bool
    {
        return in_array($operation, self::PIVOT_OPERATIONS, true);
    }

    public static function isHasManyOperation(string $operation): bool
    {
        return in_array($operation, self::HAS_MANY_OPERATIONS, true);
    }

    /**
     * Validate that the relation on the parent matches the operation kind,
     * and return the resolved relation instance.
     */
    public static function resolveRelation(Model $parent, string $relation, string $operation): BelongsToMany|BelongsTo|HasOneOrMany
    {
        if (!method_exists($parent, $relation)) {
            throw new InvalidArgumentException(
                sprintf('Model %s has no relationship named "%s".', $parent::class, $relation)
            );
        }

        $instance = $parent->{$relation}();

        if (self::isHasManyOperation($operation)) {
            if (!$instance instanceof HasOneOrMany) {
                throw new InvalidArgumentException(
                    sprintf('Operation "%s" requires a HasOne/HasMany/MorphOne/MorphMany relationship; "%s" is %s.',
                        $operation, $relation, $instance::class)
                );
            }

            return $instance;
        }

        if (self::isPivotOperation($operation)) {
            if (!$instance instanceof BelongsToMany) {
                throw new InvalidArgumentException(
                    sprintf('Operation "%s" requires a BelongsToMany/MorphToMany relationship; "%s" is %s.',
                        $operation, $relation, $instance::class)
                );
            }

            return $instance;
        }

        if (!$instance instanceof BelongsTo) {
            throw new InvalidArgumentException(
                sprintf('Operation "%s" requires a BelongsTo relationship; "%s" is %s.',
                    $operation, $relation, $instance::class)
            );
        }

        return $instance;
    }

    /**
     * Reduce whatever the caller passed (model, collection, id, or an
     * id => pivot-attributes map) into a JSON-serialisable structure.
     *
     * Returns the related model class (for associate) so the builder can
     * record it, or null when not applicable.
     */
    public static function normalizeIds(mixed $ids): mixed
    {
        if ($ids === null) {
            return null;
        }

        if ($ids instanceof Model) {
            return $ids->getKey();
        }

        if ($ids instanceof EloquentCollection) {
            return $ids->modelKeys();
        }

        if ($ids instanceof BaseCollection) {
            $ids = $ids->all();
        }

        if (is_array($ids)) {
            $normalized = [];

            foreach ($ids as $key => $value) {
                if ($value instanceof Model) {
                    $normalized[] = $value->getKey();
                } elseif (is_array($value)) {
                    // [relatedId => [pivot attributes]]
                    $normalized[$key] = $value;
                } else {
                    $normalized[] = $value;
                }
            }

            return $normalized;
        }

        return $ids;
    }

    /**
     * Reduce the operand of save/saveMany/create/createMany into a
     * JSON-serialisable list of records.
     *
     * Each record: key (existing model key or null), attributes (raw dirty
     * attributes for save*, mass-assignable input for create*) and mass
     * (whether attributes go through mass assignment on apply).
     *
     * @return list<array{key: mixed, attributes: array<string, mixed>, mass: bool}>
     */
    public static function normalizeRecords(string $operation, mixed $input): array
    {
        $items = in_array($operation, ['save', 'create'], true) ? [$input] : $input;

        if ($items instanceof BaseCollection) {
            $items = $items->all();
        }

        if (!is_iterable($items)) {
            throw new InvalidArgumentException("Operation \"{$operation}\" requires models or attribute arrays.");
        }

        $records = [];

        foreach ($items as $item) {
            if ($item instanceof Model) {
                if (!in_array($operation, ['save', 'saveMany'], true)) {
                    throw new InvalidArgumentException("Operation \"{$operation}\" requires attribute arrays, not models.");
                }

                $records[] = [
                    'key' => $item->exists ? $item->getKey() : null,
                    'attributes' => $item->exists ? $item->getDirty() : $item->getAttributes(),
                    'mass' => false,
                ];
            } elseif (is_array($item)) {
                if (!in_array($operation, ['create', 'createMany'], true)) {
                    throw new InvalidArgumentException("Operation \"{$operation}\" requires models, not attribute arrays.");
                }

                $records[] = ['key' => null, 'attributes' => $item, 'mass' => true];
            } else {
                throw new InvalidArgumentException("Operation \"{$operation}\" received an unsupported operand.");
            }
        }

        return $records;
    }

    /**
     * Capture the state needed to undo this operation later.
     *
     * For pivot relationships we snapshot the full current pivot set so any
     * operation can be reversed with a single sync(). For belongsTo we keep
     * the previous foreign-key value.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Model $parent, string $relation, string $operation, array $payload = []): array
    {
        $instance = self::resolveRelation($parent, $relation, $operation);

        if ($instance instanceof HasOneOrMany) {
            // Existing children being saved: keep the columns that will change
            // (plus the foreign key/morph type) so rollback can restore them.
            // Children created by the request are recorded when it is applied.
            $columns = [$instance->getForeignKeyName()];

            if ($instance instanceof MorphOneOrMany) {
                $columns[] = $instance->getMorphType();
            }

            $previous = [];

            foreach ($payload['records'] ?? [] as $record) {
                if ($record['key'] === null) {
                    continue;
                }

                $existing = $instance->getRelated()->newQuery()->whereKey($record['key'])->first();

                if ($existing instanceof Model) {
                    $previous[(string) $record['key']] = Arr::only(
                        $existing->getAttributes(),
                        array_merge($columns, array_keys($record['attributes']))
                    );
                }
            }

            return ['type' => 'hasMany', 'previous' => $previous];
        }

        if ($instance instanceof BelongsToMany) {
            $foreignPivotKey = $instance->getForeignPivotKeyName();
            $relatedPivotKey = $instance->getRelatedPivotKeyName();
            $parentKeyValue = $parent->getAttribute($instance->getParentKeyName());

            // newPivotQuery() (not newPivotStatement()) so a MorphToMany is
            // limited to its own morph type and not other models sharing the id.
            $rows = $instance->newPivotQuery()
                ->where($foreignPivotKey, $parentKeyValue)
                ->get();

            $pivot = [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $relatedId = $row[$relatedPivotKey];

                // Keep extra pivot columns (e.g. role, expires_at) but drop
                // the keys/identity columns that sync() manages itself.
                unset($row[$foreignPivotKey], $row[$relatedPivotKey], $row['id']);

                if ($instance instanceof MorphToMany) {
                    unset($row[$instance->getMorphType()]);
                }

                $pivot[$relatedId] = $row;
            }

            return [
                'type' => 'belongsToMany',
                'pivot' => $pivot,
            ];
        }

        // BelongsTo
        $foreignKey = $instance->getForeignKeyName();

        return [
            'type' => 'belongsTo',
            'foreign_key' => $foreignKey,
            'old_value' => $parent->getAttribute($foreignKey),
        ];
    }

    /**
     * Apply the operation. Caller is responsible for suppressing any
     * maker-checker interception around this call.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> State to persist for rollback (children created)
     */
    public static function apply(Model $parent, array $payload): array
    {
        $relation = $payload['relation'];
        $operation = $payload['operation'];
        $ids = $payload['ids'] ?? null;
        $attributes = $payload['attributes'] ?? [];
        $touch = $payload['touch'] ?? true;

        $instance = self::resolveRelation($parent, $relation, $operation);

        if ($instance instanceof HasOneOrMany) {
            return self::applyChildWrites($parent, $instance, $operation, $payload['records'] ?? []);
        }

        if ($instance instanceof BelongsTo) {
            if ($operation === 'dissociate') {
                $instance->dissociate();
            } else {
                $relatedClass = $payload['related_type'] ?? null;
                if (!is_string($relatedClass) || !class_exists($relatedClass)) {
                    throw FulfillmentException::relationError(
                        'Cannot associate: related model class is missing or invalid.'
                    );
                }
                /** @var class-string<Model> $relatedClass */
                $related = $relatedClass::query()->find($ids);
                if ($related === null) {
                    throw FulfillmentException::relationError(
                        "Cannot associate: related {$relatedClass} #{$ids} no longer exists."
                    );
                }
                $instance->associate($related);
            }

            // Persist the foreign-key change without re-triggering interception.
            $parent->saveQuietly();

            return [];
        }

        match ($operation) {
            'attach' => $instance->attach($ids, $attributes, $touch),
            'detach' => $instance->detach($ids, $touch),
            'sync' => $instance->sync($ids, $payload['detaching'] ?? true),
            'syncWithoutDetaching' => $instance->syncWithoutDetaching($ids),
            'toggle' => $instance->toggle($ids, $touch),
            'updateExistingPivot' => $instance->updateExistingPivot($ids, $attributes, $touch),
            default => throw FulfillmentException::relationError("Unknown relation operation '{$operation}'."),
        };

        return [];
    }

    /**
     * @param  list<array{key: mixed, attributes: array<string, mixed>, mass: bool}>  $records
     * @return array<string, mixed>
     */
    private static function applyChildWrites(Model $parent, HasOneOrMany $relation, string $operation, array $records): array
    {
        $related = $relation->getRelated();
        $created = [];

        foreach ($records as $record) {
            // Each write gets its own bypass: the one-time flag is consumed by
            // the first intercepted call, and the related model may be gated too.
            $model = self::withoutApproval($parent, $related, function () use ($relation, $related, $record, $operation) {
                if ($record['mass']) {
                    return $relation->create($record['attributes']);
                }

                if ($record['key'] !== null) {
                    $child = $related->newQuery()->find($record['key']);

                    if ($child === null) {
                        throw FulfillmentException::relationError(
                            "Cannot {$operation}: related ".$related::class." #{$record['key']} no longer exists."
                        );
                    }

                    $child->setRawAttributes(array_merge($child->getAttributes(), $record['attributes']));
                } else {
                    $child = $related->newInstance();
                    $child->setRawAttributes($record['attributes']);
                }

                $relation->save($child);

                return $child;
            });

            if ($record['key'] === null) {
                $created[] = $model->getKey();
            }
        }

        return ['created' => $created];
    }

    private static function withoutApproval(Model $parent, Model $related, \Closure $callback): mixed
    {
        $relatedClass = $related::class;
        $parentClass = $parent::class;

        $inner = method_exists($relatedClass, 'withoutApprovalDo')
            ? fn() => $relatedClass::withoutApprovalDo($callback)
            : $callback;

        return method_exists($parentClass, 'withoutApprovalDo')
            ? $parentClass::withoutApprovalDo($inner)
            : $inner();
    }

    /**
     * Reverse a previously applied operation using the captured snapshot.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $applied  State recorded when the request was applied
     */
    public static function reverse(Model $parent, array $payload, array $original, array $applied = []): void
    {
        $relation = $payload['relation'];
        $operation = $payload['operation'];

        if (($original['type'] ?? null) === 'hasMany') {
            $instance = self::resolveRelation($parent, $relation, $operation);
            $related = $instance->getRelated();

            self::withoutApproval($parent, $related, function () use ($related, $original, $applied): void {
                foreach ($original['previous'] ?? [] as $key => $columns) {
                    $related->newQuery()->whereKey($key)->update($columns);
                }

                $created = $applied['created'] ?? [];

                if ($created !== []) {
                    foreach ($related->newQuery()->whereKey($created)->get() as $child) {
                        $child->delete();
                    }
                }
            });

            return;
        }

        if (($original['type'] ?? null) === 'belongsTo') {
            $foreignKey = $original['foreign_key'];
            $parent->setAttribute($foreignKey, $original['old_value']);
            $parent->saveQuietly();

            return;
        }

        if (($original['type'] ?? null) === 'belongsToMany') {
            $instance = self::resolveRelation($parent, $relation, $operation);
            // Restoring the exact previous pivot set undoes attach, detach,
            // sync, toggle and updateExistingPivot in one shot.
            $instance->sync($original['pivot'] ?? []);

            return;
        }

        throw FulfillmentException::relationError(
            'Original relationship state was not captured; rollback is not possible.'
        );
    }
}
