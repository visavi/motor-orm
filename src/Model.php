<?php

declare(strict_types=1);

namespace MotorORM;

use ReflectionMethod;
use ReflectionNamedType;
use SplFileObject;
use UnexpectedValueException;

/**
 * Declaration of a table
 *
 * A model says where the data lives, what the columns mean and what the table
 * is related to. Reading and writing is the business of Query, which a model
 * hands out and never becomes
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

    /** Model and method name => whether the method is a relation */
    private static array $relationNames = [];

    /**
     * Begin querying the model
     *
     * @return Query
     */
    public static function query(): Query
    {
        return new Query(new static())->open();
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
     * Open the table file
     *
     * @return SplFileObject
     */
    public function file(): SplFileObject
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
     * @return SplFileObject
     */
    public function createFile(): SplFileObject
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
     * @return SplFileObject
     */
    private function openFile(): SplFileObject
    {
        $file = new SplFileObject($this->getPath(), 'a+');
        $file->setCsvControl(...self::CSV_CONTROL);
        $file->setFlags(
            SplFileObject::READ_AHEAD |
            SplFileObject::SKIP_EMPTY |
            SplFileObject::READ_CSV
        );
        $file->rewind();

        return $file;
    }
}
