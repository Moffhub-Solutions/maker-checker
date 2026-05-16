<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * Assert the operation name is one the package knows how to apply.
     */
    public static function assertSupported(string $operation): void
    {
        $all = array_merge(self::PIVOT_OPERATIONS, self::BELONGS_TO_OPERATIONS);

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

    /**
     * Validate that the relation on the parent matches the operation kind,
     * and return the resolved relation instance.
     */
    public static function resolveRelation(Model $parent, string $relation, string $operation): BelongsToMany|BelongsTo
    {
        if (!method_exists($parent, $relation)) {
            throw new InvalidArgumentException(
                sprintf('Model %s has no relationship named "%s".', $parent::class, $relation)
            );
        }

        $instance = $parent->{$relation}();

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
     * Capture the state needed to undo this operation later.
     *
     * For pivot relationships we snapshot the full current pivot set so any
     * operation can be reversed with a single sync(). For belongsTo we keep
     * the previous foreign-key value.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Model $parent, string $relation, string $operation): array
    {
        $instance = self::resolveRelation($parent, $relation, $operation);

        if ($instance instanceof BelongsToMany) {
            $foreignPivotKey = $instance->getForeignPivotKeyName();
            $relatedPivotKey = $instance->getRelatedPivotKeyName();
            $parentKeyValue = $parent->getAttribute($instance->getParentKeyName());

            $rows = $instance->newPivotStatement()
                ->where($foreignPivotKey, $parentKeyValue)
                ->get();

            $pivot = [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $relatedId = $row[$relatedPivotKey];

                // Keep extra pivot columns (e.g. role, expires_at) but drop
                // the keys/identity columns that sync() manages itself.
                unset($row[$foreignPivotKey], $row[$relatedPivotKey], $row['id']);

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
     */
    public static function apply(Model $parent, array $payload): void
    {
        $relation = $payload['relation'];
        $operation = $payload['operation'];
        $ids = $payload['ids'] ?? null;
        $attributes = $payload['attributes'] ?? [];
        $touch = $payload['touch'] ?? true;

        $instance = self::resolveRelation($parent, $relation, $operation);

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

            return;
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
    }

    /**
     * Reverse a previously applied operation using the captured snapshot.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $original
     */
    public static function reverse(Model $parent, array $payload, array $original): void
    {
        $relation = $payload['relation'];
        $operation = $payload['operation'];

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
