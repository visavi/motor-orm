<?php

declare(strict_types=1);

namespace MotorORM;

use ArrayIterator;
use BadMethodCallException;
use CallbackFilterIterator;
use Closure;
use Generator;
use InvalidArgumentException;
use Iterator;
use LimitIterator;
use RuntimeException;

/**
 * Query over a table
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final class Query
{
    /**
     * @param Model $model the table being queried
     */
    public function __construct(private readonly Model $model)
    {
        $this->table      = new Table($model);
        $this->mapper     = new RecordMapper($model, $this->table);
        $this->conditions = new Conditions();
        $this->sorter     = new Sorter($this->table);
        $this->writer     = new TableWriter($this->table, $this->mapper, $this->conditions);
        $this->search     = new KeySearch($this->table);
    }

    /** The file the model stands for */
    private readonly Table $table;

    /** Rows in, values out */
    private readonly RecordMapper $mapper;

    /** What the rows have to satisfy */
    private readonly Conditions $conditions;

    /** The order the rows come in */
    private readonly Sorter $sorter;

    /** Everything the query does to the table */
    private readonly TableWriter $writer;

    /** A lookup by primary key that does not read the table */
    private readonly KeySearch $search;

    private int $offset = 0;
    private int $limit = -1;

    /** The page to paginate, taken from the request when it is not spelled out */
    private ?int $page = null;

    private array $with = [];

    /**
     * Get headers
     *
     * @return array
     */
    public function headers(): array
    {
        return $this->table->headers();
    }

    /**
     * Get primary key
     *
     * @return string|null
     */
    public function getPrimaryKey(): ?string
    {
        return $this->headers()[0] ?? null;
    }

    /**
     * The rows this query asks for, filtered and in order
     *
     * @param int|null $take how many rows from the head are going to be read,
     *                       null when that is not known
     *
     * @return Iterator
     */
    private function pipeline(?int $take = null): Iterator
    {
        return $this->sorter->sort($this->filtering($this->table->records()), $take);
    }

    /**
     * The rows of a read, from the one to skip to to the last one wanted
     *
     * LimitIterator knows a limit of -1 as no limit at all, but has no notion
     * of a read of nothing, and asking it for one is an error rather than an
     * empty answer
     *
     * @param Iterator $iterator
     * @param int      $offset
     * @param int      $limit
     *
     * @return Iterator
     */
    private function limited(Iterator $iterator, int $offset, int $limit): Iterator
    {
        if ($limit === 0) {
            return new ArrayIterator();
        }

        return new LimitIterator($iterator, $offset, $limit);
    }

    /**
     * How many sorted rows a read of this query comes down to
     *
     * @param int $offset
     * @param int $limit
     *
     * @return int|null null when the read has no end
     */
    private function take(int $offset, int $limit): ?int
    {
        return $limit < 0 ? null : $offset + $limit;
    }

    /**
     * Get the default foreign key name for the model.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        $className = basename(str_replace('\\', '/', $this->model::class));
        $model = strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1_', $className));

        return  $model . '_' . $this->getPrimaryKey();
    }

    /**
     * Where
     *
     * @param Closure|string $field
     * @param mixed          $condition
     * @param mixed          $value
     * @param string         $operator
     *
     * @return $this
     */
    public function where(
        Closure|string $field,
        mixed $condition = null,
        mixed $value = null,
        string $operator = 'and'
    ): self {
        if ($field instanceof Closure) {
            /* Only the collected conditions are needed, so the table stays closed */
            $field($builder = new self($this->model));

            $this->conditions->group($operator, $builder->conditions);
        } else {
            if (func_num_args() === 2) {
                $value     = $condition;
                $condition = '=';
            }

            $this->conditions->compare($operator, $field, $condition, $value);
        }

        return $this;
    }

    /**
     * Or where
     *
     * @param Closure|string $field
     * @param mixed|null     $condition
     * @param mixed|null     $value
     *
     * @return $this
     */
    public function orWhere(Closure|string $field, mixed $condition = null, mixed $value = null): self
    {
        if ($field instanceof Closure) {
            /* Only the collected conditions are needed, so the table stays closed */
            $field($builder = new self($this->model));

            $this->conditions->group('or', $builder->conditions);
        } else {
            if (func_num_args() === 2) {
                $value     = $condition;
                $condition = '=';
            }

            $this->where($field, $condition, $value, 'or');
        }

        return $this;
    }

    /**
     * Where the value matches a pattern
     *
     * A leading or trailing % says the value may go on there, a pattern
     * without either has to match the whole value. The case is ignored
     * unless caseSensitive says otherwise
     *
     * @param string $field
     * @param string $value
     * @param bool   $caseSensitive
     *
     * @return $this
     */
    public function whereLike(string $field, string $value, bool $caseSensitive = false): self
    {
        return $this->like($field, $value, $caseSensitive, false, 'and');
    }

    /**
     * Where the value does not match a pattern
     *
     * @param string $field
     * @param string $value
     * @param bool   $caseSensitive
     *
     * @return $this
     */
    public function whereNotLike(string $field, string $value, bool $caseSensitive = false): self
    {
        return $this->like($field, $value, $caseSensitive, true, 'and');
    }

    /**
     * Or where the value matches a pattern
     *
     * @param string $field
     * @param string $value
     * @param bool   $caseSensitive
     *
     * @return $this
     */
    public function orWhereLike(string $field, string $value, bool $caseSensitive = false): self
    {
        return $this->like($field, $value, $caseSensitive, false, 'or');
    }

    /**
     * Or where the value does not match a pattern
     *
     * @param string $field
     * @param string $value
     * @param bool   $caseSensitive
     *
     * @return $this
     */
    public function orWhereNotLike(string $field, string $value, bool $caseSensitive = false): self
    {
        return $this->like($field, $value, $caseSensitive, true, 'or');
    }

    /**
     * Collect a pattern condition
     *
     * @param string $field
     * @param string $value
     * @param bool   $caseSensitive
     * @param bool   $not
     * @param string $operator
     *
     * @return $this
     */
    private function like(string $field, string $value, bool $caseSensitive, bool $not, string $operator): self
    {
        $this->conditions->pattern($operator, $field, $value, $caseSensitive, $not);

        return $this;
    }

    /**
     * Where in
     *
     * @param string $field
     * @param array  $values
     * @param string $operator
     *
     * @return $this
     */
    public function whereIn(string $field, array $values, string $operator = 'and'): self
    {
        $this->conditions->set($operator, $field, $values, false);

        return $this;
    }

    /**
     * Where not in
     *
     * @param string $field
     * @param array  $values
     * @param string $operator
     *
     * @return $this
     */
    public function whereNotIn(string $field, array $values, string $operator = 'and'): self
    {
        $this->conditions->set($operator, $field, $values, true);

        return $this;
    }

    /**
     * Sorting by asc
     *
     * @param string $field
     * @param SortOrder $sort
     *
     * @return $this
     */
    public function orderBy(string $field, SortOrder $sort = SortOrder::Asc): self
    {
        $this->sorter->by($field, $sort);

        return $this;
    }

    /**
     * Sorting by desc
     *
     * @param string $field
     *
     * @return $this
     */
    public function orderByDesc(string $field): self
    {
        $this->sorter->by($field, SortOrder::Desc);

        return $this;
    }

    /**
     * Get field by primary key
     *
     * A table normally lies sorted by its first column, and then the row can
     * be reached by halving the file rather than by reading it. That only
     * holds for a lookup and nothing else: conditions, an order or a row to
     * skip to all mean the table has to be read anyway. When the search
     * cannot vouch for what it found, the read happens as it always did
     *
     * @param int|string $id
     *
     * @return Record|null
     */
    public function find(int|string $id): ?Record
    {
        if ($this->conditions->isEmpty() && $this->sorter->isEmpty() && $this->offset === 0) {
            $row = $this->search->row((string) $id);

            if ($row !== null) {
                return $this->record($this->mapper->read($row));
            }
        }

        return $this->where($this->getPrimaryKey(), $id)->first();
    }

    /**
     * Get first record
     *
     * @return Record|null
     */
    public function first(): ?Record
    {
        /* The first row of the read, and a read may be told where it starts */
        $iterator = new LimitIterator($this->pipeline($this->offset + 1), $this->offset, 1);
        $iterator->rewind();

        /* Reading the first match is enough, counting the whole table is not */
        if (! $iterator->valid()) {
            return null;
        }

        return $this->record($this->mapper->read($iterator->current()));
    }

    /**
     * A record of its own, with whatever it was asked to bring along
     *
     * A record read on its own has no siblings, so its relations load for
     * itself alone
     *
     * @param array $values column name => value
     *
     * @return Record
     */
    private function record(array $values): Record
    {
        $record = new Record($this, $values);

        foreach ($this->with as $with => $constraint) {
            $this->loadRelation([$record], $with, $constraint);
        }

        return $record;
    }

    /**
     * Exists record
     *
     * @return bool
     */
    public function exists(): bool
    {
        $iterator = $this->filtering($this->table->records());
        $iterator->rewind();

        /* One match settles it, counting the rest is wasted work */
        return $iterator->valid();
    }

    /**
     * Get records
     *
     * @return Collection<static>
     */
    public function get(): Collection
    {
        $iterator = $this->limited($this->pipeline($this->take($this->offset, $this->limit)), $this->offset, $this->limit);

        return new Collection($this->hydrate($iterator));
    }

    /**
     * Walk the matching records one at a time
     *
     * Only the record being looked at is held in memory, so a table of any
     * size can be walked. Nothing is collected, so there are no siblings to
     * load a relation for: touching one inside the loop reads the related
     * table once per record, and with() has nothing to attach to
     *
     * @return Generator<Record>
     */
    public function cursor(): Generator
    {
        $iterator = $this->limited($this->pipeline($this->take($this->offset, $this->limit)), $this->offset, $this->limit);

        $reader = $this->mapper->reader();

        foreach ($iterator as $line) {
            yield new Record($this, $reader($line));
        }
    }

    /**
     * Get records with paginate
     *
     * The page is the one page() was told, or the one being asked for
     *
     * @param int $limit
     *
     * @return Pagination<static>
     */
    public function paginate(int $limit = 10): Pagination
    {
        $total = $this->count();

        /* Which rows to skip has to be known before they are read */
        $page   = $this->page ?? Pagination::resolveCurrentPage();
        $page   = min($page, Pagination::lastPageOf($total, $limit));
        $offset = $page * $limit - $limit;

        $iterator = $this->limited($this->pipeline($offset + $limit), $offset, $limit);

        return new Pagination($this->hydrate($iterator), $total, $limit, $page);
    }

    /**
     * Get records with paginate, without counting the table
     *
     * One row past the page is read to know whether another page follows. That
     * is the whole difference from paginate(): there are no page numbers and no
     * total, and the table is never read to the end to find them out
     *
     * @param int $limit
     *
     * @return SimplePagination<static>
     */
    public function simplePaginate(int $limit = 10): SimplePagination
    {
        /* Nothing counted the rows, so there is no last page to keep within */
        $page   = $this->page ?? SimplePagination::resolveCurrentPage();
        $offset = $page * $limit - $limit;

        $iterator = new LimitIterator($this->pipeline($offset + $limit + 1), $offset, $limit + 1);

        $records = $this->hydrate($iterator);
        $hasMore = count($records) > $limit;

        if ($hasMore) {
            /* The row that told us was never part of the page */
            array_pop($records);

            $this->siblings($records);
        }

        return new SimplePagination($records, $limit, $page, $hasMore);
    }

    /**
     * Get count records
     *
     * @return int
     */
    public function count(): int
    {
        /* With nothing to match, the rows only have to be counted, and counting
           them does not go through building an array out of every line */
        if ($this->conditions->isEmpty()) {
            return $this->table->countRecords();
        }

        return iterator_count($this->filtering($this->table->records()));
    }

    /**
     * Set limit
     *
     * @param int $limit
     *
     * @return $this
     */
    public function limit(int $limit): self
    {
        if ($limit < -1) {
            throw new InvalidArgumentException(sprintf('%s() expects the limit to be greater or equal to -1, %s given', __METHOD__, $limit));
        }

        if ($limit === $this->limit) {
            return $this;
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * Set the page to paginate
     *
     * Says outright which page paginate() and simplePaginate() are to read,
     * instead of letting them ask where the current page comes from
     *
     * @param int $page never below the first page
     *
     * @return $this
     */
    public function page(int $page): self
    {
        $this->page = max(1, $page);

        return $this;
    }

    /**
     * Set offset
     *
     * @param int $offset
     *
     * @return $this
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException(sprintf('%s() expects the offset to be a positive integer or 0, %s given', __METHOD__, $offset));
        }

        if ($this->offset === $offset) {
            return $this;
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * Create record
     *
     * @param array $values
     *
     * @return Record
     */
    public function create(array $values): Record
    {
        return new Record($this, $this->writer->insert($values));
    }

    /**
     * Write the values of a record back to its row
     *
     * @param array $attr column name => value, including the primary key
     *
     * @return bool whether the row was found
     */
    public function save(array $attr): bool
    {
        return $this->writer->save($attr);
    }

    /**
     * Update records
     *
     * @param array $values
     *
     * @return int affected rows
     */
    public function update(array $values): int
    {
        return $this->writer->update($values);
    }

    /**
     * Delete records
     *
     * @return int affected rows
     */
    public function delete(): int
    {
        return $this->writer->delete();
    }

    /**
     * Truncate file
     *
     * @return bool
     */
    public function truncate(): bool
    {
        $this->writer->truncate();

        return true;
    }

    /**
     * Eager loading
     *
     * A relation is named on its own, or named by the key of a closure that
     * narrows what this one read of it takes. The closure comes on top of
     * whatever the declaration of the relation already put on it
     *
     * @param string|array $relations
     *
     * @return $this
     */
    public function with(string|array $relations): self
    {
        $relations = (array) $relations;

        foreach ($relations as $key => $value) {
            $named      = is_int($key);
            $relation   = $named ? $value : $key;
            $constraint = $named ? null : $value;

            if (! is_string($relation)) {
                throw new InvalidArgumentException(
                    sprintf('%s() a relation is named by a string, %s names none', __METHOD__, get_debug_type($relation))
                );
            }

            if ($constraint !== null && ! $constraint instanceof Closure) {
                throw new InvalidArgumentException(
                    sprintf('%s() a relation is narrowed by a closure, %s narrows nothing', __METHOD__, get_debug_type($constraint))
                );
            }

            if (! $this->model->isRelation($relation)) {
                throw new RuntimeException(sprintf('Call to undefined relationship %s on model %s', $relation, $this->model::class));
            }

            $this->with[$relation] = $constraint;
        }

        return $this;
    }

    /**
     * Apply the callback if the given “value” is (or resolves to) truthy.
     *
     * @param mixed         $value
     * @param callable      $callback
     * @param callable|null $default
     *
     * @return $this
     */
    public function when(mixed $value, callable $callback, ?callable $default = null): self
    {
        if ($value) {
            return $callback($this, $value) ?? $this;
        }

        if ($default) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * Build a model per record, with the eager loaded relations attached
     *
     * @param iterable $values
     *
     * @return Record[]
     */
    private function hydrate(iterable $values): array
    {
        $reader = $this->mapper->reader();

        $rows = [];
        foreach ($values as $line) {
            $rows[] = new Record($this, $reader($line));
        }

        $this->siblings($rows);

        foreach ($this->with as $with => $constraint) {
            if ($rows) {
                $this->loadRelation($rows, $with, $constraint);
            }
        }

        return $rows;
    }

    /**
     * Let every record of a result know the rest of it
     *
     * The result belongs to the records, not to the query that read it: a
     * relation touched on a record long after still loads for the records it
     * was read with, and the query is free to read something else meanwhile
     *
     * @param array<Record> $rows
     *
     * @return void
     */
    private function siblings(array $rows): void
    {
        foreach ($rows as $row) {
            $row->setSiblings($rows);
        }
    }

    /**
     * Load a relation for the given records with a single query
     *
     * @param array        $rows
     * @param string       $with
     * @param Closure|null $constraint narrows this one read of the relation
     *
     * @return void
     */
    public function loadRelation(array $rows, string $with, ?Closure $constraint = null): void
    {
        new RelationLoader($this)->load($rows, $with, $constraint);
    }

    /**
     * Keep only the rows the conditions let through
     *
     * @param Iterator $iterator
     *
     * @return Iterator
     */
    private function filtering(Iterator $iterator): Iterator
    {
        if ($this->conditions->isEmpty()) {
            return $iterator;
        }

        return new CallbackFilterIterator(
            $iterator,
            fn ($current) => $this->conditions->match($current, $this->table)
        );
    }

    /**
     * @param string $name
     * @param array  $arguments
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        $scope = 'scope' . ucfirst($name);

        /* A scope is a method of the model that narrows a query */
        if (method_exists($this->model, $scope)) {
            return $this->model->$scope($this, ...$arguments);
        }

        throw new BadMethodCallException(sprintf(
            'Call to undefined method %s::%s()', $this->model::class, $name
        ));
    }

    /**
     * Name of the table being queried
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->model->getTable();
    }

    /**
     * Path of the table file
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->model->getPath();
    }

    /**
     * The table being queried
     *
     * @return Model
     */
    public function model(): Model
    {
        return $this->model;
    }
}
