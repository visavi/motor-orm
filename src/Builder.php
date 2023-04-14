<?php

declare(strict_types=1);

namespace MotorORM;

use ArrayIterator;
use BadMethodCallException;
use CallbackFilterIterator;
use Closure;
use InvalidArgumentException;
use Iterator;
use LimitIterator;
use RuntimeException;
use SplFileObject;
use SplTempFileObject;
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
    protected ?string $primary;
    protected Iterator $iterator;
    protected SplFileObject $file;

    protected array $orders = [];
    protected array $attr = [];
    protected array $relations = [];
    protected array $relate = [];
    protected array $with = [];
    protected array $where = [];
    protected array $casts = [];

    protected ?string $paginateName = null;
    protected ?string $paginateView = null;

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

    /**
     * @return SplFileObject
     */
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
    public function getTable(): string
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
        $className = basename(str_replace('\\', '/', $this::class));
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
        $find = $this->where($this->primary, $id)->first();

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
        if (! $this->exists()) {
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
     * Get refresh record
     *
     * @return Builder|null
     */
    public function refresh(): ?static
    {
        return $this->first();
    }

    /**
     * Exists record
     *
     * @return bool
     */
    public function exists(): bool
    {
        return (bool) $this->count();
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
     * Create record
     *
     * @param array $values
     *
     * @return $this
     */
    public function create(array $values): static
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

        $current = array_replace($fields, $values);
        $current = $this->prepare($current);
        $this->file->fputcsv($current);
        $this->file->flock(LOCK_UN);

        $this->attr = $values;

        return $this;
    }

    /**
     * Save record
     *
     * @return bool
     */
    public function save(): bool
    {
        $result = false;

        $this->process(function (&$current) use (&$result) {
            if ((int) $current[0] === $this->attr[$this->primary]) {
                $current = $this->prepare($this->attr);

                $result = true;
            }

            $this->file->fputcsv($current);
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
        $ids = array_column($this->mapper($this->iterator), $this->primary, $this->primary);

        $this->process(function (&$current) use ($ids, $values, &$affectedRows) {
            if (isset($ids[$current[0]])) {
                $affectedRows++;
                $map = (array) $this->mapper($current);
                $current = array_replace($map, $values);
                $current = $this->prepare($current);
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
     * Apply the callback if the given “value” is (or resolves to) truthy.
     *
     * @param mixed         $value
     * @param callable      $callback
     * @param callable|null $default
     *
     * @return $this
     */
    public function when(mixed $value, callable $callback, callable $default = null): static
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
     * Has one relation
     *
     * @param string $model
     * @param string|null $foreignKey
     * @param string|null $localKey
     *
     * @return mixed
     */
    public function hasOne(string $model, ?string $foreignKey = null, ?string $localKey = null): mixed
    {
        $model = (new $model())->query();
        $foreignKey = $foreignKey ?: $model->getForeignKey();
        $localKey   = $localKey ?: $this->getPrimaryKey();

        $relate = [
            'type'       => 'hasOne',
            'model'      => $model,
            'foreignKey' => $foreignKey,
            'localKey'   => $localKey,
        ];

        return $model->query()->setRelate($relate)->where($foreignKey, $this->$localKey);
    }

    /**
     * Has many relation
     *
     * @param string $model
     * @param string|null $localKey
     * @param string|null $foreignKey
     *
     * @return mixed
     */
    public function hasMany(string $model, ?string $foreignKey = null, ?string $localKey = null): mixed
    {
        $model = new $model();
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey   = $localKey ?: $this->getPrimaryKey();

        $relate = [
            'type'       => 'hasMany',
            'model'      => $model,
            'foreignKey' => $foreignKey,
            'localKey'   => $localKey,
        ];

        return $model->query()->setRelate($relate)->where($foreignKey, $this->$localKey);
    }

    /**
     * Has many through relation
     *
     * @param string $model
     * @param string $through
     * @param string|null $foreignKey
     * @param string|null $secondForeignKey
     * @param string|null $localKey
     * @param string|null $secondLocalKey
     *
     * @return mixed
     */
    public function hasManyThrough(
        string $model,
        string $through,
        ?string $foreignKey = null,
        ?string $secondForeignKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,

    ): mixed
    {
        $model = (new $model())->query();
        $through = (new $through())->query();

        $foreignKey       = $foreignKey ?: $this->getForeignKey();
        $secondForeignKey = $secondForeignKey ?: $model->getForeignKey();
        $localKey         = $localKey ?: $this->getPrimaryKey();
        $secondLocalKey   = $secondLocalKey ?: $through->getPrimaryKey();

        $relate = [
            'type'             => 'hasManyThrough',
            'model'            => $model,
            'through'          => $through,
            'foreignKey'       => $foreignKey,
            'secondForeignKey' => $secondForeignKey,
            'localKey'         => $localKey,
            'secondLocalKey'   => $secondLocalKey,
        ];

        $throughKeys = $through
            ->query()
            ->where($foreignKey, $this->$localKey)
            ->get()
            ->pluck($secondForeignKey);

        return $model->query()->setRelate($relate)->whereIn($secondLocalKey, $throughKeys);
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

            $record = array_combine($this->headers, $record);

            array_walk($record, function (mixed &$value, string $field) {
                if ($value === '') {
                    $value = null;
                } elseif (isset($this->casts[$field])) {
                    $value = $this->cast($this->casts[$field], $value);
                } elseif (
                    $this->getPrimaryKey() === $field
                    || str_ends_with($field, '_id')
                    || str_ends_with($field, '_at')
                ) {
                    $value = (int) $value;
                }
            });

            return $record;
        };
    }

    /**
     * Mapper fields
     *
     * @param iterable $values
     *
     * @return $this[]|$this
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
        if ($rows && $this->with) {
            $relations = [];
            foreach ($this->with as $with) {
                foreach ($rows as $row) {
                    $method = $row->$with();
                    if (! $row->attr[$method->relate['localKey']]) {
                        continue;
                    }

                    $localId = $row->attr[$method->relate['localKey']];
                    $relations[$with][$localId] = $localId;
                }

                $relate = $this->$with()->relate;
                $where = $this->$with()->where;

                $where['and'][0] = [
                    'field'     => $relate['foreignKey'],
                    'condition' => 'in',
                    'value'     => $relations[$with],
                ];

                $relationData = $relate['model']->query()->setWhere($where)->get();

                $relationByKey = [];
                foreach ($relationData as $data) {
                    $foreignKey = $relate['foreignKey'];
                    $relationByKey[$data->$foreignKey] = $data;
                }

                array_walk($rows, static function ($row) use ($relate, $relationByKey, $with) {
                    $localKey = $relate['localKey'];
                    $row->relations[$with] = $relationByKey[$row->$localKey] ?? null;
                });
            }
        }

        return $rows;
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
        };

        $lax = static function(mixed $field, mixed $value) {
            return mb_strtolower($field, 'UTF-8') === mb_strtolower($value, 'UTF-8');
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
            'lax' => $lax($field, $value),
            default => $field === $value,
        };
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
        $valid = [];

        foreach ($wheres as $key => $where) {
            if (isset($where['field'])) {
                $field = $args[$this->getKeyByField($where['field'])];
                $valid[] = $this->condition($field, $where['condition'], $where['value']);
            } else {
                $valid[] = $this->checker($where, $args, $key);
            }
        }

        if ($operator === 'or') {
            if (in_array(true, $valid, true)) {
                return true;
            }
            return false;
        }
        if (in_array(false, $valid, true)) {
            return false;
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
     * @param array $relate
     *
     * @return $this
     */
    private function setRelate(array $relate): static
    {
        $this->relate = $relate;

        return $this;
    }

    /**
     * Set where
     *
     * @param array $where
     *
     * @return $this
     */
    private function setWhere(array $where): static
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
        if (method_exists($this, 'scope' . ucfirst($name))) {
            return $this->{'scope' . ucfirst($name)}($this, ...$arguments);
        }

        throw new BadMethodCallException(sprintf(
            'Call to undefined method %s::%s()', static::class, $name
        ));
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

            if (isset($this->relations[$field])) {
                return $this->relations[$field];
            }

            return $this->$field()->relate['type'] === 'hasOne'
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
        return array_key_exists($field, $this->attr);
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        unset($this->file, $this->iterator);
    }
}
