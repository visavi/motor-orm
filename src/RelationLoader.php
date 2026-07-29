<?php

declare(strict_types=1);

namespace MotorORM;

use Closure;

/**
 * Loader of declared relations
 *
 * A relation is read for a whole result at once: the keys of the rows are
 * collected, the related table is read once for all of them, and every row
 * gets its share. Reading a relation inside a loop would read that table
 * again for each row
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final readonly class RelationLoader
{
    /**
     * @param Query $parent the query the rows were read by
     */
    public function __construct(private Query $parent) {}

    /**
     * Attach a relation to every row
     *
     * @param array<Record> $rows
     * @param string        $with       name the relation is declared under
     * @param Closure|null  $constraint narrows this one read of the relation
     *
     * @return void
     */
    public function load(array $rows, string $with, ?Closure $constraint = null): void
    {
        $relation = $this->parent->model()->relation($with);
        $relation->resolve($this->parent);

        $localIds = $this->localIds($rows, $relation->localKey);

        if ($relation->type === RelationType::HasManyThrough) {
            $this->loadThrough($rows, $with, $relation, $localIds, $constraint);

            return;
        }

        $model      = $relation->model;
        $foreignKey = $relation->foreignKey;

        $related = [];
        if ($localIds) {
            $query = $model::query()->whereIn($foreignKey, $localIds);

            /* What the declaration says first, what this read asks for on top */
            $relation->applyTo($query);
            $constraint?->__invoke($query);

            $related = $query->get();
        }

        $byKey = [];
        foreach ($related as $record) {
            if ($relation->isSingle()) {
                $byKey[$record->$foreignKey] ??= $record;
            } else {
                $byKey[$record->$foreignKey][] = $record;
            }
        }

        $emptyQuery = null;

        foreach ($rows as $row) {
            $found = $byKey[$row->attr[$relation->localKey] ?? null] ?? null;

            if (! $relation->isSingle()) {
                $row->setRelation($with, new Collection($found ?? []));

                continue;
            }

            /* A missing hasOne gives an empty record, never null */
            if ($found === null) {
                $emptyQuery ??= $model::query();
                $found = new Record($emptyQuery);
            }

            $row->setRelation($with, $found);
        }
    }

    /**
     * Collect unique local keys of the given rows
     *
     * @param array  $rows
     * @param string $localKey
     *
     * @return array
     */
    private function localIds(array $rows, string $localKey): array
    {
        $localIds = [];
        foreach ($rows as $row) {
            $localId = $row->attr[$localKey] ?? null;

            if ($localId) {
                $localIds[$localId] = $localId;
            }
        }

        return $localIds;
    }

    /**
     * Load a hasManyThrough relation
     *
     * @param array    $rows
     * @param string   $with
     * @param Relation $relation
     * @param array        $localIds
     * @param Closure|null $constraint
     *
     * @return void
     */
    private function loadThrough(
        array $rows,
        string $with,
        Relation $relation,
        array $localIds,
        ?Closure $constraint = null,
    ): void
    {
        $foreignKey       = $relation->foreignKey;
        $secondForeignKey = $relation->secondForeignKey;
        $model            = $relation->model;
        $through          = $relation->through;

        $secondKeysByLocal = [];
        $secondKeys        = [];

        if ($localIds) {
            foreach ($through::query()->whereIn($foreignKey, $localIds)->get() as $link) {
                $secondKeysByLocal[$link->$foreignKey][] = $link->$secondForeignKey;
                $secondKeys[$link->$secondForeignKey]    = $link->$secondForeignKey;
            }
        }

        $records = [];
        if ($secondKeys) {
            $query = $model::query()->whereIn($relation->secondLocalKey, $secondKeys);

            $relation->applyTo($query);
            $constraint?->__invoke($query);

            foreach ($query->get() as $record) {
                $records[$record->{$relation->secondLocalKey}] = $record;
            }
        }

        foreach ($rows as $row) {
            $localId = $row->attr[$relation->localKey] ?? null;

            $items = [];
            foreach ($secondKeysByLocal[$localId] ?? [] as $secondKey) {
                if (isset($records[$secondKey])) {
                    $items[] = $records[$secondKey];
                }
            }

            $row->setRelation($with, new Collection($items));
        }
    }
}
