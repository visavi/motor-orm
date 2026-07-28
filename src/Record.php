<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * One row of a table
 *
 * A record holds its values and nothing else. Reading and writing the table is
 * the business of the query it came from, which is why a record carries no
 * conditions, no file handle and no way to run a query of its own
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
class Record
{
    /** Relations already loaded on this record */
    private array $relations = [];

    /**
     * The records this one was read together with, itself among them
     *
     * A relation touched later is loaded for the whole result at once, and the
     * result is the one the record came from, not whatever its query read last
     */
    private array $siblings = [];

    /**
     * @param Query $query the query the record was read with
     * @param array   $attr  column name => value
     */
    public function __construct(
        private readonly Query $query,
        /** Column name => value, changed through the record itself only */
        private(set) array $attr = [],
    ) {}

    /**
     * A fresh query on the same table
     *
     * @return Query
     */
    public function newQuery(): Query
    {
        return $this->query->model()::query();
    }

    /**
     * Read the record from the table again, dropping the unsaved changes
     *
     * @return static|null null when the record is no longer there
     */
    public function fresh(): ?static
    {
        return $this->newQuery()->find($this->key());
    }

    /**
     * Write the values of the record back to its row
     *
     * @return bool whether the row was found
     */
    public function save(): bool
    {
        return $this->newQuery()->save($this->attr);
    }

    /**
     * Remove the row of the record
     *
     * @return int affected rows
     */
    public function delete(): int
    {
        return $this->newQuery()->where($this->query->getPrimaryKey(), $this->key())->delete();
    }

    /**
     * Change the given columns of the row
     *
     * @param array $values
     *
     * @return int affected rows
     */
    public function update(array $values): int
    {
        $affected = $this->newQuery()->where($this->query->getPrimaryKey(), $this->key())->update($values);

        $this->attr = array_replace($this->attr, $values);

        return $affected;
    }

    /**
     * Value of the primary key
     *
     * @return int|string|null
     */
    public function key(): int|string|null
    {
        return $this->attr[$this->query->getPrimaryKey()] ?? null;
    }

    /**
     * Whether the relation has already been loaded on this record
     *
     * @param string $relation
     *
     * @return bool
     */
    public function relationLoaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    /**
     * Tell the record what it was read together with
     *
     * @param array<Record> $records
     *
     * @return void
     */
    public function setSiblings(array $records): void
    {
        $this->siblings = $records;
    }

    /**
     * Attach a loaded relation
     *
     * @param string $relation
     * @param mixed  $value
     *
     * @return void
     */
    public function setRelation(string $relation, mixed $value): void
    {
        $this->relations[$relation] = $value;
    }

    /**
     * The record as a plain array
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->attr;
    }

    /**
     * @param string $field
     *
     * @return mixed
     */
    public function __get(string $field): mixed
    {
        if (! array_key_exists($field, $this->attr) && $this->query->model()->isRelation($field)) {
            if (! array_key_exists($field, $this->relations)) {
                /* Loading it for every record of the same result costs one query, not one per record */
                $this->query->loadRelation($this->siblings ?: [$this], $field);
            }

            return $this->relations[$field];
        }

        return $this->attr[$field] ?? null;
    }

    /**
     * @param string $field
     * @param mixed  $value
     *
     * @return void
     */
    public function __set(string $field, mixed $value): void
    {
        $this->attr[$field] = $value;
    }

    /**
     * @param string $field
     *
     * @return bool
     */
    public function __isset(string $field): bool
    {
        return isset($this->attr[$field]);
    }
}
