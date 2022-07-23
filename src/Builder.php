<?php

declare(strict_types=1);

namespace MotorORM;

use ArrayIterator;
use CallbackFilterIterator;
use Closure;
use InvalidArgumentException;
use Iterator;
use LimitIterator;
use RuntimeException;
use SplFileObject;
use SplTempFileObject;
use stdClass;
use UnexpectedValueException;

/**
 * Builder file ORM
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 * @version 3.0
 */
abstract class Builder
{
    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public const SORT_TYPES = [
        self::SORT_ASC,
        self::SORT_DESC
    ];

    protected string $filePath;
    protected int $offset = 0;
    protected int $limit = -1;
    protected array $headers;
    protected int|string $primary;
    protected Iterator $iterator;
    protected SplFileObject $file;
    protected array $orders = [];
    protected array $attr = [];
    protected ?string $paginateName = null;
    protected ?string $paginateView = null;
    protected ?string $relate = null;
    protected array $relations = [];
    protected array $with = [];
    protected array $where = [];

    /**
     * Begin querying the model.
     *
     * @return $this
     */
    public static function query(): static
    {
        return (new static())->open();
    }

    /**
     * Open file
     *
     * @return $this
     */
    public function open(): static
    {
        $this->file    = $this->file();
        $this->headers = $this->headers();
        $this->primary = $this->getPrimaryKey();

        $this->iterator = new LimitIterator($this->file, 1);

        /* Fix drop new line */
        $this->iterator = new CallbackFilterIterator(
            $this->iterator,
            fn ($current) => $current !== [null]
        );

        return $this;
    }

    public function file(): SplFileObject
    {
        $file = new SplFileObject($this->filePath, 'a+');
        $file->setFlags(
            SplFileObject::READ_AHEAD |
            SplFileObject::SKIP_EMPTY |
            SplFileObject::READ_CSV
        );
        $file->rewind();

        return $file;
    }

    /**
     * Get table
     *
     * @return string
     */
    public function getTable()
    {
        return basename($this->filePath, '.csv');
    }

    /**
     * Get headers
     *
     * @return array
     */
    public function headers(): array
    {
        $this->file->seek(0);

        return $this->file->current();
    }

    /**
     * Get primary key
     *
     * @return int|string
     */
    public function getPrimaryKey(): int|string
    {
        return $this->headers[0];
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
    ): static {
        if ($field instanceof Closure) {
            $field($builder = self::query());

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
    public function orWhere(Closure|string $field, mixed $condition = null, mixed $value = null): static
    {
        if ($field instanceof Closure) {
            $field($builder = self::query());

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
    public function whereIn(string $field, array $values, string $operator = 'and'): static
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
    public function whereNotIn(string $field, array $values, string $operator = 'and'): static
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
     * @param string      $field
     * @param string|null $sort
     *
     * @return $this
     */
    public function orderBy(string $field, ?string $sort = self::SORT_ASC): static
    {
        if (! in_array($sort, self::SORT_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('%s(), Argument #2 must be a valid sort flag', __METHOD__));
        }

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
    public function orderByDesc(string $field): static
    {
        $this->orders[$field] = self::SORT_DESC;

        return $this;
    }

    /**
     * Get field by primary key
     *
     * @param int|string $id
     *
     * @return static|null
     */
    public function find(int|string $id): ?static
    {
        $find = $this->where($this->getPrimaryKey(), $id)->first();

        if (! $find) {
            return null;
        }

        return $this;
    }

    /**
     * Get first record
     *
     * @return static|null
     */
    public function first(): ?static
    {
        if (! $this->count()) {
            return null;
        }

        $this->filtering();
        $this->sorting();
        $this->iterator = new LimitIterator($this->iterator, 0, 1);

        $this->iterator->rewind();
        $this->attr = $this->mapper($this->iterator->current());

        return $this;
    }

    /**
     * Get records
     *
     * @return Collection<static>|static[]
     */
    public function get(): Collection
    {
        $this->filtering();
        $this->sorting();
        $this->iterator = new LimitIterator($this->iterator, $this->offset, $this->limit);

        return new Collection($this->mapper($this->iterator));
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
    public function limit(int $limit): static
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
    public function offset(int $offset): static
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
     * Insert record
     *
     * @param array $values
     *
     * @return $this
     */
    public function insert(array $values): static
    {
        $fields   = array_fill_keys($this->headers, '');
        $diffKeys = array_diff_key($values, $fields);

        if ($diffKeys) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, key($diffKeys)));
        }

        if (! $this->file->flock(LOCK_EX)) {
            throw new UnexpectedValueException(sprintf('Unable to obtain lock on file: %s', $this->file->getFilename()));
        }

        $ids = array_column($this->mapper($this->iterator), $this->primary, $this->primary);

        if (! isset($values[$this->primary])) {
            if ($ids) {
                $maxId = max($ids);
                if (is_numeric($maxId)) {
                    ++$maxId;
                } else {
                    throw new UnexpectedValueException(sprintf('%s() no unique ID assigned. Column "%s" cannot be generated', __METHOD__, $this->primary));
                }
            } else {
                $maxId = 1;
            }

            $values[$this->primary] = $maxId;
        }

        if (isset($ids[$values[$this->primary]])) {
            throw new UnexpectedValueException(sprintf('%s() duplicate entry. Column "%s" with the value "%s" already exists', __METHOD__, $this->primary, $values[$this->primary]));
        }

        $this->file->fputcsv(array_replace($fields, $values));
        $this->file->flock(LOCK_UN);

        $this->attr = $values;

        return $this;
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
        $ids = array_column($this->mapper($this->iterator), $this->primary, $this->primary);

        $this->process(function (&$current) use ($ids, $values, &$affectedRows) {
            if (isset($ids[$current[0]])) {
                $affectedRows++;
                $map = (array) $this->mapper($current);
                $current = array_replace($map, $values);
            }

            $this->file->fputcsv($current);
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
        $ids = array_column($this->mapper($this->iterator), $this->primary, $this->primary);

        $this->process(function (&$current) use ($ids, &$affectedRows) {
            if (isset($ids[$current[0]])) {
                $affectedRows++;
            } else {
                $this->file->fputcsv($current);
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
        if (! $this->file->flock(LOCK_EX)) {
            throw new UnexpectedValueException(sprintf('Unable to obtain lock on file: %s', $this->file->getFilename()));
        }

        $this->file->seek(0);
        $this->file->ftruncate($this->file->ftell());
        $this->file->flock(LOCK_UN);

        return true;
    }

    /**
     * Eager loading
     *
     * @param string|array $relations
     *
     * @return $this
     */
    public function with(string|array $relations): static
    {
        $relations = (array) $relations;

        foreach ($relations as $relation) {
            if (! method_exists($this, $relation)) {
                throw new RuntimeException(sprintf('Call to undefined relationship %s on model %s', $relation, $this::class));
            }

            $this->with[] = $relation;
        }

        return $this;
    }

    /**
     * Has one relation
     *
     * @param string $model
     * @param string $localKey
     * @param string $foreignKey
     *
     * @return mixed
     */
    public function hasOne(string $model, string $localKey, string $foreignKey = 'id'): mixed
    {
        $model = new $model();

        return $model->query()->setRelate('hasOne')->where($foreignKey, $this->$localKey);
    }

    /**
     * Has many relation
     *
     * @param string $model
     * @param string $localKey
     * @param string $foreignKey
     *
     * @return mixed
     */
    public function hasMany(string $model, string $localKey, string $foreignKey = 'id'): mixed
    {
        $model = new $model();

        return $model->query()->setRelate('hasMany')->where($foreignKey, $this->$localKey);


        /*if (! $this->attr) {
            return [$model, $localKey, $foreignKey, __FUNCTION__];
        }

        $model = new $model();
        $k = $model->getTable() . '.' .  $localKey . '.' . $foreignKey;

        return $this->relations[$k] ?? $model->query()->where($foreignKey, $this->$localKey)->get();*/
    }


    /**
     * Combine fields
     *
     * @return Closure
     */
    protected function combiner(): Closure
    {
        $fieldCount = count($this->headers);

        return function (array $record) use ($fieldCount): array {
            if (count($record) !== $fieldCount) {
                $record = array_slice(array_pad($record, $fieldCount, null), 0, $fieldCount);
            }

            $record = array_map([$this, 'cast'], $record);

            return array_combine($this->headers, $record);
        };
    }

    /**
     * Mapper fields
     *
     * @param iterable $values
     *
     * @return stdClass[]|stdClass
     */
    protected function mapper(iterable $values): array|object
    {
        $combiner = $this->combiner();

        if (is_array($values)) {
            return $combiner($values);
        }

        $rows = [];
        foreach ($values as $line) {
            $clone = clone $this;
            $clone->attr = $combiner($line);
            $rows[] = $clone;
        }

        // Parse relation
        if ($this->with) {
            $relations = [];
            foreach ($this->with as $with) {
                $v = $this->$with();
                /** @var Builder $builder */
                [$builder, $localKey, $foreignKey] = $v;
                $k = (new $builder())->getTable() . '.' .  $localKey . '.' . $foreignKey;

                foreach ($rows as $row) {
                    if (! $row->attr[$localKey]) {
                        continue;
                    }

                    $relations[$with][$row->attr[$localKey]] = $row->attr[$localKey];
                }

                if ($relations) {
                    foreach ($relations as $relation) {
                        $relationData = $builder::query()->whereIn($foreignKey, $relation)->get();

                        array_walk($rows, static function ($row) use ($relationData, $k, $v) {
                            [, $localKey, $foreignKey, $relateType] = $v;

                            $neededObject = new Collection(
                                array_filter(
                                    $relationData->toArray(),
                                    static function ($e) use ($localKey, $foreignKey, $row) {
                                        return $e->$foreignKey === $row->$localKey;
                                    }
                                )
                            );

                            $row->relations[$k] = $relateType === 'hasOne' ? $neededObject->first() : $neededObject;
                        });
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Cast field
     *
     * @param string $value
     *
     * @return mixed
     */
    protected function cast(string $value): mixed
    {
        if (is_numeric($value)) {
            return ! str_contains($value, '.') ? (int) $value : (float) $value;
        }

        if ($value === '') {
            return null;
        }

        return $value;
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

        $this->iterator = new ArrayIterator(iterator_to_array($this->iterator));

        $this->iterator->uasort(
            function($a, $b) {
                $retVal = 0;
                foreach ($this->orders as $field => $sort) {
                    $key = $this->getKeyByField($field);

                    if ($retVal === 0) {
                        if ($sort === self::SORT_ASC) {
                            $retVal = $a[$key] <=> $b[$key];
                        } else {
                            $retVal = $b[$key] <=> $a[$key];
                        }
                    }
                }

                return $retVal;
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
        $like = static function(mixed $field, mixed $value) {
            if ($value[0] === '%' && $value[-1] === '%') {
                return str_contains($field, trim($value, '%'));
            }

            if ($value[0] === '%') {
                return str_starts_with($field, trim($value, '%'));
            }

            if ($value[-1] === '%') {
                return str_ends_with($field, trim($value, '%'));
            }

            return str_contains($field, $value);
        };

        return match ($condition) {
            '!=', '<>' => $field !== $value,
            '>=' => $field >= $value,
            '<=' => $field <= $value,
            '>' => $field > $value,
            '<' => $field < $value,
            'in' => isset($value[$field]),
            'not_in' => ! isset($value[$field]),
            'like' => $like($field, $value),
            'not_like' => ! $like($field, $value),
            default => $field === $value,
        };
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
        $valids = [];

        foreach ($wheres as $key => $where) {
            if (isset($where['field'])) {
                $field = $args[$this->getKeyByField($where['field'])];
                $valids[] = $this->condition($field, $where['condition'], $where['value']);
            } else {
                $valids[] = $this->checker($where, $args, $key);
            }
        }

        if ($operator === 'or') {
            foreach ($valids as $valid) {
                if ($valid === true) {
                    return true;
                }
            }
            return false;
        }

        foreach ($valids as $valid) {
            if ($valid === false) {
                return false;
            }
        }

        return true;
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
        $key = array_search($field, $this->headers, true);

        if ($key === false) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, $field));
        }

        return $key;
    }

    /**
     * Process
     *
     * @param Closure $closure
     *
     * @return void
     */
    private function process(Closure $closure): void
    {
        if (! $this->file->flock(LOCK_EX)) {
            throw new UnexpectedValueException(sprintf('Unable to obtain lock on file: %s', $this->file->getFilename()));
        }

        $this->file->fseek(0);

        $temp = new SplTempFileObject(-1);
        $temp->setFlags(
            SplFileObject::READ_AHEAD |
            SplFileObject::SKIP_EMPTY |
            SplFileObject::READ_CSV
        );

        while(! $this->file->eof()) {
            $temp->fwrite($this->file->fread(4096));
        }

        $temp->rewind();
        $this->file->ftruncate(0);
        $this->file->fseek(0);

        while ($temp->valid()) {
            $current = $temp->current();

            $closure($current);
            $temp->next();
        }

        $this->file->flock(LOCK_UN);
    }

    /**
     * Set relate
     *
     * @param string $relate
     *
     * @return $this
     */
    private function setRelate(string $relate): static
    {
        $this->relate = $relate;

        return $this;
    }

    /**
     * @param string $field
     *
     * @return null
     */
    public function __get(string $field)
    {
        if (method_exists($this, $field)) {
            $class = get_class($this->$field());

            return $this->$field()->relate === 'hasOne'
                ? $this->$field()->first() ?? new $class()
                : $this->$field()->get();
        }

        return $this->attr[$field] ?? null;
    }

    /**
     * @param string $field
     * @param mixed  $value
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
    public function __isset(string $field)
    {
        return isset($this->attr[$field]);
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        unset($this->file, $this->iterator);
    }
}
