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
use SplFileObject;
use SplHeap;
use UnexpectedValueException;

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
    }

    /**
     * Rows a page may ask for before holding the whole order is cheaper
     *
     * Keeping a heap costs an insert per row, sorting once costs a sort. The
     * fewer rows are wanted, the more the heap wins, and past a page or two
     * the one sort at the end takes it back
     */
    private const int SORT_TAKE_LIMIT = 1000;

    /** The file the model stands for */
    private readonly Table $table;

    /** Rows in, values out */
    private readonly RecordMapper $mapper;

    /** What the rows have to satisfy */
    private readonly Conditions $conditions;

    private int $offset = 0;
    private int $limit = -1;

    private array $orders = [];
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
        return $this->sorting($this->filtering($this->table->records()), $take);
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
        $this->orders[$field] = $sort;

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
        $this->orders[$field] = SortOrder::Desc;

        return $this;
    }

    /**
     * Get field by primary key
     *
     * @param int|string $id
     *
     * @return Record|null
     */
    public function find(int|string $id): ?Record
    {
        return $this->where($this->getPrimaryKey(), $id)->first();
    }

    /**
     * Get first record
     *
     * @return Record|null
     */
    public function first(): ?Record
    {
        $iterator = new LimitIterator($this->pipeline(1), 0, 1);
        $iterator->rewind();

        /* Reading the first match is enough, counting the whole table is not */
        if (! $iterator->valid()) {
            return null;
        }

        $record = new Record($this, $this->mapper->read($iterator->current()));

        /* A record read on its own has no siblings, its relations load for itself */
        foreach ($this->with as $with) {
            $this->loadRelation([$record], $with);
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
     * Which page to show is decided by the caller, reading the request is
     * none of the query's business
     *
     * @param int $limit
     * @param int $page
     *
     * @return Pagination<static>
     */
    public function paginate(int $limit = 10, int $page = 1): Pagination
    {
        $total = $this->count();

        /* Which rows to skip has to be known before they are read */
        $page   = min(max(1, $page), Pagination::lastPageOf($total, $limit));
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
     * @param int $page
     *
     * @return SimplePagination<static>
     */
    public function simplePaginate(int $limit = 10, int $page = 1): SimplePagination
    {
        $page   = max(1, $page);
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
        $primary  = $this->getPrimaryKey();
        $fields   = array_fill_keys($this->headers(), '');
        $diffKeys = array_diff_key($values, $fields);

        if ($diffKeys) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, key($diffKeys)));
        }

        $lock = $this->table->lock();

        try {
            /* Another writer may have replaced the file, read it as it is now */
            $this->table->close();

            $ids = $this->primaryKeys($this->table->records());

            if (! isset($values[$primary])) {
                $values[$primary] = $this->nextPrimaryKey($ids);
            }

            if (isset($ids[$values[$primary]])) {
                throw new UnexpectedValueException(sprintf('%s() duplicate entry. Column "%s" with the value "%s" already exists', __METHOD__, $primary, $values[$primary]));
            }

            $current = array_replace($fields, $values);
            $current = $this->mapper->write($current);
            $this->table->file()->fputcsv($current);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return new Record($this, $values);
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
        $result = false;
        $key    = (string) ($attr[$this->getPrimaryKey()] ?? '');

        $this->table->rewrite(function (array &$current, SplFileObject $target) use (&$result, $attr, $key) {
            if ((string) $current[0] === $key) {
                $current = $this->mapper->write($attr);

                $result = true;
            }

            $target->fputcsv($current);
        });

        return $result;
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
        $diffKeys = array_diff_key($values, array_flip($this->headers()));

        if ($diffKeys) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, key($diffKeys)));
        }

        $affectedRows = 0;
        $ids          = $this->primaryKeys($this->filtering($this->table->records()));

        $reader = $this->mapper->reader();

        $this->table->rewrite(function (array &$current, SplFileObject $target) use ($reader, $ids, $values, &$affectedRows) {
            if (isset($ids[$current[0]])) {
                $affectedRows++;
                $current = array_replace($reader($current), $values);
                $current = $this->mapper->write($current);
            }

            $target->fputcsv($current);
        });

        return $affectedRows;
    }

    /**
     * Delete records
     *
     * @return int affected rows
     */
    public function delete(): int
    {
        $affectedRows = 0;
        $ids          = $this->primaryKeys($this->filtering($this->table->records()));

        $this->table->rewrite(function (array $current, SplFileObject $target) use ($ids, &$affectedRows) {
            if (isset($ids[$current[0]])) {
                $affectedRows++;
            } else {
                $target->fputcsv($current);
            }
        });

        return $affectedRows;
    }

    /**
     * Truncate file
     *
     * @return bool
     */
    public function truncate(): bool
    {
        /* Only the column names survive, the rest of the table is never read */
        $headers = $this->table->headers();

        $this->table->replace(static function (SplFileObject $target) use ($headers) {
            $target->fputcsv($headers);
        });

        return true;
    }

    /**
     * Eager loading
     *
     * @param string|array $relations
     *
     * @return $this
     */
    public function with(string|array $relations): self
    {
        $relations = (array) $relations;

        foreach ($relations as $relation) {
            if (! $this->model->isRelation($relation)) {
                throw new RuntimeException(sprintf('Call to undefined relationship %s on model %s', $relation, $this->model::class));
            }

            $this->with[] = $relation;
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

        foreach ($this->with as $with) {
            if ($rows) {
                $this->loadRelation($rows, $with);
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
     * @param array  $rows
     * @param string $with
     *
     * @return void
     */
    public function loadRelation(array $rows, string $with): void
    {
        new RelationLoader($this)->load($rows, $with);
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
     * Put the rows in the order that was asked for
     *
     * @param Iterator $iterator
     * @param int|null $take how many rows from the head are going to be read
     *
     * @return Iterator
     */
    private function sorting(Iterator $iterator, ?int $take): Iterator
    {
        if (! $this->orders) {
            return $iterator;
        }

        /* Resolve the column positions once instead of on every comparison */
        $orders = [];
        foreach ($this->orders as $field => $sort) {
            $orders[$this->table->keyOf($field)] = $sort === SortOrder::Asc;
        }

        $comparer = static function (array $a, array $b) use ($orders): int {
            foreach ($orders as $key => $asc) {
                $retVal = $asc ? $a[$key] <=> $b[$key] : $b[$key] <=> $a[$key];

                if ($retVal !== 0) {
                    return $retVal;
                }
            }

            return 0;
        };

        if ($take !== null && $take <= self::SORT_TAKE_LIMIT) {
            return $this->leading($iterator, $comparer, $take);
        }

        /* Nothing says how much of the order is wanted, so all of it is held at once */
        $sorted = new ArrayIterator(iterator_to_array($iterator));

        $sorted->uasort($comparer);

        return $sorted;
    }

    /**
     * The first rows of the order, without holding the rest
     *
     * Sorting still has to look at every row, but carrying every row is only
     * needed when every row is wanted. For a page it is enough to keep the
     * rows that would be on it: a heap holds the worst of them on top, so a
     * row that cannot beat it is dropped where it is read
     *
     * @param Iterator $iterator
     * @param Closure  $comparer
     * @param int      $take
     *
     * @return Iterator
     */
    private function leading(Iterator $iterator, Closure $comparer, int $take): Iterator
    {
        if ($take === 0) {
            return new ArrayIterator();
        }

        /* Rows the order cannot tell apart keep the order they were read in */
        $sequence = 0;
        $heap     = new class ($comparer) extends SplHeap {
            public function __construct(private readonly Closure $comparer) {}

            public function compare($value1, $value2): int
            {
                return ($this->comparer)($value1[1], $value2[1]) ?: $value1[0] <=> $value2[0];
            }
        };

        foreach ($iterator as $row) {
            $current = [$sequence++, $row];

            if ($heap->count() < $take) {
                $heap->insert($current);

                continue;
            }

            /* The top is the worst of the rows kept, so it is the one to give way */
            if ($heap->compare($current, $heap->top()) < 0) {
                $heap->extract();
                $heap->insert($current);
            }
        }

        /* A heap gives up its rows worst first, and the head of the order is wanted */
        $leading = [];
        foreach ($heap as [, $row]) {
            array_unshift($leading, $row);
        }

        return new ArrayIterator($leading);
    }

    /**
     * Continue a numeric primary key from the highest one already taken
     *
     * @param array<array-key, string> $ids
     *
     * @return int|float the next free key
     */
    private function nextPrimaryKey(array $ids): int|float
    {
        if (! $ids) {
            return 1;
        }

        $maxId = max($ids);

        /* Keys are read as raw strings, only a numeric one can be continued */
        if (! is_numeric($maxId)) {
            throw new UnexpectedValueException(sprintf('%s() no unique ID assigned. Column "%s" cannot be generated', __METHOD__, $this->getPrimaryKey()));
        }

        return ++$maxId;
    }

    /**
     * Collect the primary keys of the current selection, reading the raw
     * records instead of building a model for every row
     *
     * @return array<array-key, string> the raw key of every record, as its own array key
     */
    private function primaryKeys(Iterator $iterator): array
    {
        $key = $this->table->keyOf($this->getPrimaryKey());

        $ids = [];
        foreach ($iterator as $record) {
            $id = (string) ($record[$key] ?? '');

            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return $ids;
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
