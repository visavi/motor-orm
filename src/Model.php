<?php

declare(strict_types=1);

namespace MotorORM;

use ReflectionMethod;
use ReflectionNamedType;
use UnexpectedValueException;

/**
 * A table and one row of it
 *
 * A model says where the data lives, what the columns mean and what the table
 * is related to, and an instance read from the file holds the values of one
 * row. Reading is the business of Query: a model hands one out and carries no
 * conditions of its own, so a row never drags the query that found it along
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
abstract class Model
{
    /**
     * Separator, enclosure and escape character used for every csv file
     *
     * Nothing escapes: a quote inside a value is written twice, as RFC 4180
     * says. The backslash php escapes with by default cannot close a value
     * that ends in one, so such a value used to run into the rows below
     */
    public const array CSV_CONTROL = [',', '"', ''];

    /** Path to the data file, relative to tableDir when it is set */
    protected string $table;
    protected ?string $tableDir = null;

    /** Column name => cast */
    protected array $casts = [];

    /** Column name => value, empty until the model is read from the file */
    private(set) array $attr = [];

    /** The query the row was read with, null for a model that is only a table */
    private ?Query $query = null;

    /** Relations already loaded on this row */
    private array $relations = [];

    /**
     * The rows this one was read together with, itself among them
     *
     * A relation touched later is loaded for the whole result at once, and the
     * result is the one the row came from, not whatever its query read last
     */
    private array $siblings = [];

    /** Model and method name => whether the method is a relation */
    private static array $relationNames = [];

    /**
     * Begin querying the model
     *
     * @return Query
     */
    public static function query(): Query
    {
        return new Query(new static());
    }

    /**
     * Path of the table file, without touching the filesystem
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->tableDir ? $this->tableDir . '/' . $this->table : $this->table;
    }

    /**
     * Name of the table, taken from the file name
     *
     * @return string
     */
    public function getTable(): string
    {
        return pathinfo($this->table, PATHINFO_FILENAME);
    }

    /**
     * Declared casts, column name => cast
     *
     * @return array
     */
    public function getCasts(): array
    {
        return $this->casts;
    }

    /**
     * One row of this table, holding the given values
     *
     * The declaration is the same for every row, so a row is a copy of the
     * model that was asked, and whatever a model of this table can answer a
     * row answers too
     *
     * @param Query $query the query the row is read with
     * @param array $attr  column name => value
     *
     * @return static
     */
    public function newRow(Query $query, array $attr = []): static
    {
        $row = clone $this;

        $row->query     = $query;
        $row->attr      = $attr;
        $row->relations = [];
        $row->siblings  = [];

        return $row;
    }

    /**
     * Open the table file
     *
     * @return CsvFile
     */
    public function file(): CsvFile
    {
        if (! is_file($this->getPath())) {
            throw new UnexpectedValueException(
                sprintf('%s() table "%s" does not exist', __METHOD__, $this->getTable())
            );
        }

        return $this->openFile();
    }

    /**
     * Open the table file, creating it when it is not there yet
     *
     * Reading must not bring a table into being, so only a migration
     * goes through here
     *
     * @return CsvFile
     */
    public function createFile(): CsvFile
    {
        return $this->openFile();
    }

    /**
     * Declare a one-to-one relation
     *
     * @param string      $model
     * @param string|null $foreignKey
     * @param string|null $localKey
     *
     * @return Relation
     */
    public function hasOne(string $model, ?string $foreignKey = null, ?string $localKey = null): Relation
    {
        return new Relation(RelationType::HasOne, $model, $foreignKey, $localKey);
    }

    /**
     * Declare a one-to-many relation
     *
     * @param string      $model
     * @param string|null $foreignKey
     * @param string|null $localKey
     *
     * @return Relation
     */
    public function hasMany(string $model, ?string $foreignKey = null, ?string $localKey = null): Relation
    {
        return new Relation(RelationType::HasMany, $model, $foreignKey, $localKey);
    }

    /**
     * Declare a many-to-many relation through an intermediate table
     *
     * @param string      $model
     * @param string      $through
     * @param string|null $foreignKey
     * @param string|null $secondForeignKey
     * @param string|null $localKey
     * @param string|null $secondLocalKey
     *
     * @return Relation
     */
    public function hasManyThrough(
        string $model,
        string $through,
        ?string $foreignKey = null,
        ?string $secondForeignKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,
    ): Relation {
        return new Relation(
            RelationType::HasManyThrough,
            $model,
            $foreignKey,
            $localKey,
            $through,
            $secondForeignKey,
            $secondLocalKey,
        );
    }

    /**
     * A fresh query on the same table
     *
     * @return Query
     */
    public function newQuery(): Query
    {
        return static::query();
    }

    /**
     * Read the row from the table again, dropping the unsaved changes
     *
     * @return static|null null when the row is no longer there
     */
    public function fresh(): ?static
    {
        return $this->newQuery()->find($this->key());
    }

    /**
     * Write the values of the row back to the table
     *
     * A row that has no key yet is one nothing has written: it is inserted,
     * and the key the table hands out becomes its own
     *
     * @return bool whether the row was written
     */
    public function save(): bool
    {
        if ($this->key() === null) {
            $this->attr = $this->newQuery()->create($this->attr)->toArray();

            return true;
        }

        return $this->newQuery()->save($this->attr);
    }

    /**
     * Remove the row from the table
     *
     * @return int affected rows
     */
    public function delete(): int
    {
        return $this->newQuery()->where($this->primaryKey(), $this->key())->delete();
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
        $affected = $this->newQuery()->where($this->primaryKey(), $this->key())->update($values);

        $this->attr = array_replace($this->attr, $values);

        return $affected;
    }

    /**
     * Value of the primary key
     *
     * @return int|string|null null for a row nothing has written yet
     */
    public function key(): int|string|null
    {
        return $this->attr[$this->primaryKey()] ?? null;
    }

    /**
     * Name of the first column, the one the keys live in
     *
     * @return string|null
     */
    public function primaryKey(): ?string
    {
        return ($this->query ?? $this->newQuery())->getPrimaryKey();
    }

    /**
     * The row as a plain array
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->attr;
    }

    /**
     * Whether the relation has already been loaded on this row
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
     * Tell the row what it was read together with
     *
     * @param array<static> $rows
     *
     * @return void
     */
    public function setSiblings(array $rows): void
    {
        $this->siblings = $rows;
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
     * @param string $field
     *
     * @return mixed
     */
    public function __get(string $field): mixed
    {
        if (! array_key_exists($field, $this->attr) && $this->isRelation($field)) {
            if (! array_key_exists($field, $this->relations)) {
                /* Loading it for every row of the same result costs one query, not one per row */
                ($this->query ?? $this->newQuery())->loadRelation($this->siblings ?: [$this], $field);
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

    /**
     * Whether the name belongs to a relation of this model
     *
     * @param string $field
     *
     * @return bool
     */
    public function isRelation(string $field): bool
    {
        if (! method_exists($this, $field)) {
            return false;
        }

        $key = static::class . '::' . $field;

        return self::$relationNames[$key] ??= self::declaresRelation(new ReflectionMethod($this, $field));
    }

    /**
     * The relation declared under the given name
     *
     * @param string $field
     *
     * @return Relation
     */
    public function relation(string $field): Relation
    {
        return $this->$field();
    }

    /**
     * Whether the method is a relation declared by the model
     *
     * The methods a model inherits are never relations, and a method of its
     * own counts only when it says it returns one. Reading a property can
     * therefore never run a method that does something else
     *
     * @param ReflectionMethod $method
     *
     * @return bool
     */
    private static function declaresRelation(ReflectionMethod $method): bool
    {
        if ($method->getDeclaringClass()->getName() === self::class) {
            return false;
        }

        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType && $type->getName() === Relation::class;
    }

    /**
     * Open the file for reading and appending
     *
     * @return CsvFile
     */
    private function openFile(): CsvFile
    {
        /* Binary mode, so a row is the same bytes on every system */
        return new CsvFile($this->getPath(), 'a+b', ...self::CSV_CONTROL);
    }
}
