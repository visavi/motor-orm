<?php

declare(strict_types=1);

namespace MotorORM;

use Closure;

/**
 * Description of a relation between two tables
 *
 * Declaring a relation states what it connects and nothing else. No file is
 * opened and no query is built until the relation is actually loaded, and the
 * keys that were left out are worked out at that point
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
class Relation
{
    /**
     * @param RelationType $type
     * @param string      $model            class of the table being related to
     * @param string|null $foreignKey       column of the related table, guessed when null
     * @param string|null $localKey         column of this table, guessed when null
     * @param string|null $through          class of the intermediate table
     * @param string|null $secondForeignKey column of the related table the intermediate one points at
     * @param string|null $secondLocalKey   column of the intermediate table, guessed when null
     */
    public function __construct(
        public readonly RelationType $type,
        public readonly string $model,
        public ?string $foreignKey = null,
        public ?string $localKey = null,
        public readonly ?string $through = null,
        public ?string $secondForeignKey = null,
        public ?string $secondLocalKey = null,
    ) {}

    /** Extra conditions the declaration puts on the related table */
    private ?Closure $constraint = null;

    /**
     * Narrow the related records further
     *
     * @param Closure $callback called with the query on the related table
     *
     * @return $this
     */
    public function constrain(Closure $callback): static
    {
        $this->constraint = $callback;

        return $this;
    }

    /**
     * Apply the extra conditions to a query on the related table
     *
     * @param Query $query
     *
     * @return void
     */
    public function applyTo(Query $query): void
    {
        if ($this->constraint) {
            ($this->constraint)($query);
        }
    }

    /**
     * Whether the relation holds at most one record
     *
     * @return bool
     */
    public function isSingle(): bool
    {
        return $this->type === RelationType::HasOne;
    }

    /**
     * Fill in the keys that were not spelled out
     *
     * @param Query $parent the table the relation is declared on
     *
     * @return $this
     */
    public function resolve(Query $parent): static
    {
        $related = $this->model;
        $through = $this->through;

        $this->localKey ??= $parent->getPrimaryKey();

        if ($this->type === RelationType::HasOne) {
            $this->foreignKey ??= $related::query()->getForeignKey();

            return $this;
        }

        $this->foreignKey ??= $parent->getForeignKey();

        if ($this->type === RelationType::HasManyThrough) {
            $this->secondForeignKey ??= $related::query()->getForeignKey();
            $this->secondLocalKey   ??= $through::query()->getPrimaryKey();
        }

        return $this;
    }
}
