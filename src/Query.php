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
use Throwable;
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
    public function __construct(private readonly Model $model) {}

    /** Separator, enclosure and escape character used for every csv file */
    /**
     * The cast the primary key gets when the model declares none: a generated
     * key is a number and reads back as one, a key that is not stays untouched
     */
    private const string CAST_KEY = 'primary_key';

    protected int $offset = 0;
    protected int $limit = -1;
    protected array $headers;
    /** Column name => position, resolved once per query */
    protected array $headerKeys = [];
    protected ?string $primary;
    protected Iterator $iterator;
    protected SplFileObject $file;

    protected array $orders = [];
    /** Records of the last result, so a relation loads for all of them at once */
    private array $rows = [];
    protected array $with = [];
    protected array $where = [];

    protected ?string $paginateView = null;
    protected ?string $paginateName = null;

    /**
     * Open file
     *
     * @return $this
     */
    public function open(): self
    {
        $this->file       = $this->model->file();
        $this->headers    = $this->headers();
        $this->headerKeys = array_flip($this->headers);
        $this->primary    = $this->getPrimaryKey();

        $this->iterator = new LimitIterator($this->file, 1);

        /* Fix drop new line */
        $this->iterator = new CallbackFilterIterator(
            $this->iterator,
            fn ($current) => $current !== [null]
        );

        return $this;
    }

    /**
     * Get headers
     *
     * @return array
     */
    public function headers(): array
    {
        $this->file->seek(0);

        return $this->file->current() ?: [];
    }

    /**
     * Get primary key
     *
     * @return string|null
     */
    public function getPrimaryKey(): ?string
    {
        return $this->headers[0] ?? null;
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

            $this->where[$operator][] = $builder->where;
        } else {
            if (func_num_args() === 2) {
                $value     = $condition;
                $condition = '=';
            }

            $this->where[$operator][] = [
                'field'     => $field,
                'condition' => $condition,
                'value'     => (string) $value,
            ];
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

            $this->where['or'][] = $builder->where;
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
        $values = array_flip($values);

        $this->where[$operator][] = [
            'field'     => $field,
            'condition' => 'in',
            'value'     => $values
        ];

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
        $values = array_flip($values);

        $this->where[$operator][] = [
            'field'     => $field,
            'condition' => 'not_in',
            'value'     => $values
        ];

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
        return $this->where($this->primary, $id)->first();
    }

    /**
     * Get first record
     *
     * @return Record|null
     */
    public function first(): ?Record
    {
        $this->filtering();
        $this->sorting();
        $this->iterator = new LimitIterator($this->iterator, 0, 1);

        $this->iterator->rewind();

        /* Reading the first match is enough, counting the whole table is not */
        if (! $this->iterator->valid()) {
            return null;
        }

        $record = new Record($this, $this->combiner()($this->iterator->current()));

        /* A record read on its own has no siblings, its relations load for itself */
        $this->rows = [$record];

        foreach ($this->with as $with) {
            $this->loadRelation($this->rows, $with);
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
        $this->filtering();
        $this->iterator->rewind();

        /* One match settles it, counting the rest is wasted work */
        return $this->iterator->valid();
    }

    /**
     * Get records
     *
     * @return Collection<static>
     */
    public function get(): Collection
    {
        $this->filtering();
        $this->sorting();
        $this->iterator = new LimitIterator($this->iterator, $this->offset, $this->limit);

        return new Collection($this->mapper($this->iterator));
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
        $this->filtering();
        $this->sorting();
        $this->iterator = new LimitIterator($this->iterator, $this->offset, $this->limit);

        $combiner = $this->combiner();

        foreach ($this->iterator as $line) {
            yield new Record($this, $combiner($line));
        }
    }

    /**
     * Get records with paginate
     *
     * @param int $limit
     *
     * @return CollectionPaginate<static>
     */
    public function paginate(int $limit = 10): CollectionPaginate
    {
        $paginator = new Pagination($this->paginateView, $this->paginateName);
        $paginator = $paginator->create($this->count(), $limit);

        $this->filtering();
        $this->sorting();
        $this->iterator = new LimitIterator($this->iterator, $paginator->offset, $paginator->limit);

        return new CollectionPaginate($this->mapper($this->iterator), $paginator);
    }

    /**
     * Get count records
     *
     * @return int
     */
    public function count(): int
    {
        $this->filtering();

        return iterator_count($this->iterator);
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
        $fields   = array_fill_keys($this->headers, '');
        $diffKeys = array_diff_key($values, $fields);

        if ($diffKeys) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, key($diffKeys)));
        }

        $lock = $this->lockForWrite();

        try {
            /* Another writer may have replaced the file, read it as it is now */
            $this->reopen();

            $ids = $this->primaryKeys();

            if (! isset($values[$this->primary])) {
                $values[$this->primary] = $this->nextPrimaryKey($ids);
            }

            if (isset($ids[$values[$this->primary]])) {
                throw new UnexpectedValueException(sprintf('%s() duplicate entry. Column "%s" with the value "%s" already exists', __METHOD__, $this->primary, $values[$this->primary]));
            }

            $current = array_replace($fields, $values);
            $current = $this->prepare($current);
            $this->file->fputcsv($current);
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
        $key    = (string) ($attr[$this->primary] ?? '');

        $this->rewrite(function (array &$current, SplFileObject $target) use (&$result, $attr, $key) {
            if ((string) $current[0] === $key) {
                $current = $this->prepare($attr);

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
        $diffKeys = array_diff_key($values, array_flip($this->headers));

        if ($diffKeys) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, key($diffKeys)));
        }

        $affectedRows = 0;
        $this->filtering();
        $ids = $this->primaryKeys();

        $combiner = $this->combiner();

        $this->rewrite(function (array &$current, SplFileObject $target) use ($combiner, $ids, $values, &$affectedRows) {
            if (isset($ids[$current[0]])) {
                $affectedRows++;
                $current = array_replace($combiner($current), $values);
                $current = $this->prepare($current);
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
        $this->filtering();
        $ids = $this->primaryKeys();

        $this->rewrite(function (array $current, SplFileObject $target) use ($ids, &$affectedRows) {
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
        $this->replace(function (SplFileObject $target) {
            $target->fputcsv($this->model->file()->current() ?: []);
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
     * Combine fields
     *
     * @return Closure
     */
    protected function combiner(): Closure
    {
        $headers    = $this->headers;
        $fieldCount = count($headers);
        $casts      = $this->model->getCasts();

        if ($this->primary !== null && ! isset($casts[$this->primary])) {
            $casts[$this->primary] = self::CAST_KEY;
        }

        return function (array $record) use ($headers, $fieldCount, $casts): array {
            if (count($record) !== $fieldCount) {
                $record = array_slice(array_pad($record, $fieldCount, null), 0, $fieldCount);
            }

            $record = array_combine($headers, $record);

            foreach ($record as $field => $value) {
                if ($value === '') {
                    $record[$field] = null;
                } elseif (isset($casts[$field])) {
                    $record[$field] = $this->cast($casts[$field], $value);
                }
            }

            return $record;
        };
    }

    /**
     * Build a model per record, with the eager loaded relations attached
     *
     * @param iterable $values
     *
     * @return Record[]
     */
    protected function mapper(iterable $values): array
    {
        $combiner = $this->combiner();

        $rows = [];
        foreach ($values as $line) {
            $rows[] = new Record($this, $combiner($line));
        }

        /*
         * The query holds on to the whole result, so that touching a relation
         * on one record later can load it for all of them at once instead of
         * scanning the related table once per record
         */
        $this->rows = $rows;

        foreach ($this->with as $with) {
            if ($rows) {
                $this->loadRelation($rows, $with);
            }
        }

        return $rows;
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
        /** @var Relation $relation */
        $relation = $this->model->relation($with);
        $relation->resolve($this);

        $localIds = $this->localIds($rows, $relation->localKey);

        if ($relation->type === RelationType::HasManyThrough) {
            $this->eagerLoadThrough($rows, $with, $relation, $localIds);

            return;
        }

        $model      = $relation->model;
        $foreignKey = $relation->foreignKey;

        $related = [];
        if ($localIds) {
            $query = $model::query()->whereIn($foreignKey, $localIds);
            $relation->applyTo($query);

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
     * @param array    $localIds
     *
     * @return void
     */
    private function eagerLoadThrough(array $rows, string $with, Relation $relation, array $localIds): void
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

    /**
     * Apply condition
     *
     * @return void
     */
    private function filtering(): void
    {
        if (! $this->where) {
            return;
        }

        $this->iterator = new CallbackFilterIterator(
            $this->iterator,
            fn ($current) => $this->checker($this->where, $current)
        );
    }

    /**
     * Sorting
     *
     * @return void
     */
    private function sorting(): void
    {
        if (! $this->orders) {
            return;
        }

        /* Resolve the column positions once instead of on every comparison */
        $orders = [];
        foreach ($this->orders as $field => $sort) {
            $orders[$this->getKeyByField($field)] = $sort === SortOrder::Asc;
        }

        $this->iterator = new ArrayIterator(iterator_to_array($this->iterator));

        $this->iterator->uasort(
            static function ($a, $b) use ($orders) {
                foreach ($orders as $key => $asc) {
                    $retVal = $asc ? $a[$key] <=> $b[$key] : $b[$key] <=> $a[$key];

                    if ($retVal !== 0) {
                        return $retVal;
                    }
                }

                return 0;
            }
        );
    }

    /**
     * Condition operator
     *
     * @param mixed $field
     * @param string $condition
     * @param mixed $value
     *
     * @return bool
     */
    private function condition(mixed $field, string $condition, mixed $value = null): bool
    {
        return match ($condition) {
            '!=', '<>' => $field !== $value,
            '>=' => $field >= $value,
            '<=' => $field <= $value,
            '>' => $field > $value,
            '<' => $field < $value,
            'in' => isset($value[$field]),
            'not_in' => ! isset($value[$field]),
            'like' => self::like($field, $value),
            'not_like' => ! self::like($field, $value),
            'lax' => self::lax($field, $value),
            default => $field === $value,
        };
    }

    /**
     * Like comparison
     *
     * @param mixed $field
     * @param mixed $value
     *
     * @return bool
     */
    private static function like(mixed $field, mixed $value): bool
    {
        if (! $value) {
            return false;
        }

        $value = (string) $value;
        if ($value[0] === '%' && $value[-1] === '%') {
            return mb_stripos($field, trim($value, '%'), 0, 'UTF-8') !== false;
        }

        if ($value[0] === '%') {
            $value = trim($value, '%');
            return mb_strripos($field, $value, 0, 'UTF-8') === mb_strlen($field, 'UTF-8') - mb_strlen($value, 'UTF-8');
        }

        if ($value[-1] === '%') {
            return mb_stripos($field, trim($value, '%'), 0, 'UTF-8') === 0;
        }

        return mb_stripos($field, $value, 0, 'UTF-8') !== false;
    }

    /**
     * Case insensitive comparison
     *
     * @param mixed $field
     * @param mixed $value
     *
     * @return bool
     */
    private static function lax(mixed $field, mixed $value): bool
    {
        return mb_strtolower((string) $field, 'UTF-8') === mb_strtolower((string) $value, 'UTF-8');
    }

    /**
     *  Cast
     *
     * @param string $cast
     * @param mixed  $value
     *
     * @return mixed
     */
    private function cast(string $cast, mixed $value): mixed
    {
        return match ($cast) {
            self::CAST_KEY => is_numeric($value) ? (int) $value : $value,
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'object' => json_decode($value, false),
            'array' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Prepare
     *
     * @param $current
     *
     * @return array
     */
    private function prepare($current): array
    {
        return array_map(static function ($value) {
            if ($value === false) {
                return '0';
            }

            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            return (string) $value;
        }, $current);
    }

    /**
     * Checker condition
     *
     * @param array $wheres
     * @param array $args
     * @param mixed $operator
     *
     * @return bool
     */
    private function checker(array $wheres, array $args, mixed $operator = 'or'): bool
    {
        $isOr = $operator === 'or';

        foreach ($wheres as $key => $where) {
            if (isset($where['field'])) {
                $field  = $args[$this->getKeyByField($where['field'])];
                $result = $this->condition($field, $where['condition'], $where['value']);
            } else {
                /* A nested group is stored under a numeric key, its own keys carry the operators */
                $result = $this->checker($where, $args, is_string($key) ? $key : 'or');
            }

            /* A true settles an or, a false settles an and */
            if ($isOr === $result) {
                return $result;
            }
        }

        return ! $isOr;
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
            throw new UnexpectedValueException(sprintf('%s() no unique ID assigned. Column "%s" cannot be generated', __METHOD__, $this->primary));
        }

        return ++$maxId;
    }

    /**
     * Collect the primary keys of the current selection, reading the raw
     * records instead of building a model for every row
     *
     * @return array<array-key, string> the raw key of every record, as its own array key
     */
    private function primaryKeys(): array
    {
        $key = $this->getKeyByField($this->primary);

        $ids = [];
        foreach ($this->iterator as $record) {
            $id = (string) ($record[$key] ?? '');

            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return $ids;
    }

    /**
     * Get key by name
     *
     * @param string $field
     *
     * @return int
     */
    private function getKeyByField(string $field): int
    {
        $key = $this->headerKeys[$field] ?? false;

        if ($key === false) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, $field));
        }

        return $key;
    }

    /**
     * Rewrite the table row by row
     *
     * The rows go to a sibling file that replaces the table in one atomic
     * step, so a reader never sees a half written table and a failure
     * leaves the original untouched
     *
     * @param Closure $closure called with the record, the file being written and the one being read
     *
     * @return void
     */
    public function rewrite(Closure $closure): void
    {
        $this->replace(function (SplFileObject $target) use ($closure) {
            $source = $this->model->file();

            foreach ($source as $current) {
                /* Fix drop new line */
                if ($current === [null]) {
                    continue;
                }

                $closure($current, $target, $source);
            }
        });
    }

    /**
     * Put a freshly written file in place of the table
     *
     * The rows go to a sibling file that replaces the table in one atomic
     * step, so a reader never sees a half written table and a failure
     * leaves the original untouched
     *
     * @param Closure $writer called with the file to write the new table into
     *
     * @return void
     */
    private function replace(Closure $writer): void
    {
        $path     = $this->model->getPath();
        $tempPath = $path . '.tmp';
        $lock     = $this->lockForWrite();

        try {
            $target = new SplFileObject($tempPath, 'w');
            $target->setCsvControl(...Model::CSV_CONTROL);

            try {
                $writer($target);
            } catch (Throwable $exception) {
                unset($target);
                unlink($tempPath);

                throw $exception;
            }

            unset($target);

            chmod($tempPath, fileperms($path) & 0777);

            /* One atomic step: a reader sees either the old table or the new one */
            if (! rename($tempPath, $path)) {
                unlink($tempPath);

                throw new UnexpectedValueException(sprintf('Unable to replace table file: %s', $path));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->reopen();
    }

    /**
     * Take the write lock on the table
     *
     * A write replaces the file by a rename, so a lock held on a file that
     * has just been swapped out protects nothing. Check that the handle
     * still refers to the file the path points at, and take the lock again
     * when another writer replaced it while we were waiting
     *
     * @return resource
     */
    private function lockForWrite()
    {
        while (true) {
            $handle = @fopen($this->model->getPath(), 'r');

            if ($handle === false) {
                throw new UnexpectedValueException(
                    sprintf('%s() table "%s" does not exist', __METHOD__, $this->model->getTable())
                );
            }

            if (! flock($handle, LOCK_EX)) {
                fclose($handle);

                throw new UnexpectedValueException(sprintf('Unable to obtain lock on file: %s', $this->model->getPath()));
            }

            $locked  = fstat($handle);
            $current = @stat($this->model->getPath());

            if ($current !== false && $current['ino'] === $locked['ino']) {
                return $handle;
            }

            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Point the builder at the file as it is now, after it was replaced
     *
     * @return void
     */
    private function reopen(): void
    {
        $this->file = $this->model->file();

        $this->iterator = new LimitIterator($this->file, 1);

        /* Fix drop new line */
        $this->iterator = new CallbackFilterIterator(
            $this->iterator,
            fn ($current) => $current !== [null]
        );
    }

    /**
     * Set where
     *
     * @param array $where
     *
     * @return $this
     */
    private function setWhere(array $where): self
    {
        $this->where = $where;

        return $this;
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

    /**
     * Records of the last result
     *
     * @return Record[]
     */
    public function rows(): array
    {
        return $this->rows;
    }
}
