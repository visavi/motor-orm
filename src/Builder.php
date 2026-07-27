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
 * @version 3.x
 */
abstract class Builder
{
    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public const SORT_TYPES = [
        self::SORT_ASC,
        self::SORT_DESC
    ];

    /** Separator, enclosure and escape character used for every csv file */
    public const CSV_CONTROL = [',', '"', '\\'];

    protected string $table;
    protected ?string $tableDir = null;

    protected int $offset = 0;
    protected int $limit = -1;
    protected array $headers;
    /** Column name => position, resolved once per query */
    protected array $headerKeys = [];
    /** Column name => cast, resolved once per query */
    protected array $castPlan = [];
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

    protected ?string $paginateView = null;
    protected ?string $paginateName = null;

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
        $this->file       = $this->file();
        $this->headers    = $this->headers();
        $this->headerKeys = array_flip($this->headers);
        $this->primary    = $this->getPrimaryKey();

        $this->buildCastPlan();

        $this->iterator = new LimitIterator($this->file, 1);

        /* Fix drop new line */
        $this->iterator = new CallbackFilterIterator(
            $this->iterator,
            fn ($current) => $current !== [null]
        );

        return $this;
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
     * Open the table file, creating it when it does not exist yet
     *
     * @return SplFileObject
     */
    public function file(): SplFileObject
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

    /**
     * Get table
     *
     * @return string
     */
    public function getTable(): string
    {
        return pathinfo($this->table, PATHINFO_FILENAME);
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
            /* Only the collected conditions are needed, so the table stays closed */
            $field($builder = new static());

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
            /* Only the collected conditions are needed, so the table stays closed */
            $field($builder = new static());

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
     * @param string $field
     * @param string $sort
     *
     * @return $this
     */
    public function orderBy(string $field, string $sort = self::SORT_ASC): static
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
        $this->filtering();
        $this->sorting();
        $this->iterator = new LimitIterator($this->iterator, 0, 1);

        $this->iterator->rewind();

        /* Reading the first match is enough, counting the whole table is not */
        if (! $this->iterator->valid()) {
            return null;
        }

        $this->attr = $this->combiner()($this->iterator->current());

        return $this;
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

        try {
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
            $this->file->flock(LOCK_UN);
        }

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
            if ((string) $current[0] === (string) $this->attr[$this->primary]) {
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
        $ids = $this->primaryKeys();

        $combiner = $this->combiner();

        $this->process(function (&$current) use ($combiner, $ids, $values, &$affectedRows) {
            if (isset($ids[$current[0]])) {
                $affectedRows++;
                $current = array_replace($combiner($current), $values);
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

        if ($this->attr) {
            $ids = [$this->attr[$this->primary] => $this->attr[$this->primary]];
        } else {
            $ids = $this->primaryKeys();
        }

        $this->process(function ($current) use ($ids, &$affectedRows) {
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

        try {
            $this->file->seek(0);
            $this->file->ftruncate($this->file->ftell());
        } finally {
            $this->file->flock(LOCK_UN);
        }

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
    public function when(mixed $value, callable $callback, ?callable $default = null): static
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
     * @return self
     */
    public function hasOne(string $model, ?string $foreignKey = null, ?string $localKey = null): self
    {
        /* Same as query(), spelled so that a class name in a variable resolves */
        /** @var self $query */
        $query = (new $model())->open();

        $foreignKey = $foreignKey ?: $query->getForeignKey();
        $localKey   = $localKey ?: $this->getPrimaryKey();

        $relate = [
            'type'       => 'hasOne',
            'model'      => new $model(),
            'foreignKey' => $foreignKey,
            'localKey'   => $localKey,
        ];

        return $query->setRelate($relate)->where($foreignKey, $this->$localKey);
    }

    /**
     * Has many relation
     *
     * @param string $model
     * @param string|null $localKey
     * @param string|null $foreignKey
     *
     * @return self
     */
    public function hasMany(string $model, ?string $foreignKey = null, ?string $localKey = null): self
    {
        $model      = new $model();
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey   = $localKey ?: $this->getPrimaryKey();

        $relate = [
            'type'       => 'hasMany',
            'model'      => $model,
            'foreignKey' => $foreignKey,
            'localKey'   => $localKey,
        ];

        return $model::query()->setRelate($relate)->where($foreignKey, $this->$localKey);
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
     * @return self
     */
    public function hasManyThrough(
        string $model,
        string $through,
        ?string $foreignKey = null,
        ?string $secondForeignKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,
    ): self {
        /* Same as query(), spelled so that a class name in a variable resolves */
        /** @var self $modelQuery */
        $modelQuery = (new $model())->open();
        /** @var self $throughQuery */
        $throughQuery = (new $through())->open();

        $foreignKey       = $foreignKey ?: $this->getForeignKey();
        $secondForeignKey = $secondForeignKey ?: $modelQuery->getForeignKey();
        $localKey         = $localKey ?: $this->getPrimaryKey();
        $secondLocalKey   = $secondLocalKey ?: $throughQuery->getPrimaryKey();

        $relate = [
            'type'             => 'hasManyThrough',
            'model'            => new $model(),
            'through'          => new $through(),
            'foreignKey'       => $foreignKey,
            'secondForeignKey' => $secondForeignKey,
            'localKey'         => $localKey,
            'secondLocalKey'   => $secondLocalKey,
        ];

        $throughKeys = $throughQuery
            ->where($foreignKey, $this->$localKey)
            ->get()
            ->pluck($secondForeignKey)
            ->all();

        return $modelQuery->setRelate($relate)->whereIn($secondLocalKey, $throughKeys);
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

        return function (array $record) use ($headers, $fieldCount): array {
            if (count($record) !== $fieldCount) {
                $record = array_slice(array_pad($record, $fieldCount, null), 0, $fieldCount);
            }

            $record = array_combine($headers, $record);

            foreach ($record as $field => $value) {
                if ($value === '') {
                    $record[$field] = null;
                } elseif (isset($this->castPlan[$field])) {
                    $record[$field] = $this->cast($this->castPlan[$field], $value);
                }
            }

            return $record;
        };
    }

    /**
     * Resolve the cast of every column once per query instead of per record
     *
     * @return void
     */
    private function buildCastPlan(): void
    {
        $primary = $this->getPrimaryKey();

        $this->castPlan = [];
        foreach ($this->headers as $field) {
            if (isset($this->casts[$field])) {
                $this->castPlan[$field] = $this->casts[$field];
            } elseif (
                $primary === $field
                || str_ends_with($field, '_id')
                || str_ends_with($field, '_at')
            ) {
                $this->castPlan[$field] = 'int';
            }
        }
    }

    /**
     * Build a model per record, with the eager loaded relations attached
     *
     * @param iterable $values
     *
     * @return $this[]
     */
    protected function mapper(iterable $values): array
    {
        $combiner = $this->combiner();

        $rows = [];
        foreach ($values as $line) {
            $clone = clone $this;
            $clone->attr = $combiner($line);
            $rows[] = $clone;
        }

        // Parse relation
        if ($rows && $this->with) {
            foreach ($this->with as $with) {
                $relation = $this->$with();
                $relate   = $relation->relate;
                $localIds = $this->localIds($rows, $relate['localKey']);

                if ($relate['type'] === 'hasManyThrough') {
                    $this->eagerLoadThrough($rows, $with, $relate, $localIds);

                    continue;
                }

                $where = $relation->where;
                $where['and'][0] = [
                    'field'     => $relate['foreignKey'],
                    'condition' => 'in',
                    'value'     => $localIds,
                ];

                /** @var self $related */
                $related      = $relate['model'];
                $relationData = $related::query()->setWhere($where)->get();

                $foreignKey = $relate['foreignKey'];
                $relationByKey = [];
                foreach ($relationData as $data) {
                    if ($relate['type'] === 'hasOne') {
                        $relationByKey[$data->$foreignKey] ??= $data;
                    } else {
                        $relationByKey[$data->$foreignKey][] = $data;
                    }
                }

                foreach ($rows as $row) {
                    $localId = $row->attr[$relate['localKey']] ?? null;
                    $related = $relationByKey[$localId] ?? null;

                    $row->relations[$with] = $relate['type'] === 'hasOne'
                        ? $related
                        : new Collection($related ?? []);
                }
            }
        }

        return $rows;
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
     * Eager load a hasManyThrough relation
     *
     * @param array  $rows
     * @param string $with
     * @param array  $relate
     * @param array  $localIds
     *
     * @return void
     */
    private function eagerLoadThrough(array $rows, string $with, array $relate, array $localIds): void
    {
        $foreignKey       = $relate['foreignKey'];
        $secondForeignKey = $relate['secondForeignKey'];
        $secondLocalKey   = $relate['secondLocalKey'];

        $secondKeysByLocal = [];
        $secondKeys        = [];

        if ($localIds) {
            /** @var self $throughModel */
            $throughModel = $relate['through'];
            $through      = $throughModel::query()->whereIn($foreignKey, $localIds)->get();

            foreach ($through as $link) {
                $secondKeysByLocal[$link->$foreignKey][] = $link->$secondForeignKey;
                $secondKeys[$link->$secondForeignKey]    = $link->$secondForeignKey;
            }
        }

        $models = [];
        if ($secondKeys) {
            /** @var self $relatedModel */
            $relatedModel = $relate['model'];
            $related      = $relatedModel::query()->whereIn($secondLocalKey, $secondKeys)->get();

            foreach ($related as $model) {
                $models[$model->$secondLocalKey] = $model;
            }
        }

        foreach ($rows as $row) {
            $localId = $row->attr[$relate['localKey']] ?? null;

            $items = [];
            foreach ($secondKeysByLocal[$localId] ?? [] as $secondKey) {
                if (isset($models[$secondKey])) {
                    $items[] = $models[$secondKey];
                }
            }

            $row->relations[$with] = new Collection($items);
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
            $orders[$this->getKeyByField($field)] = $sort === self::SORT_ASC;
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

        try {
            $this->file->fseek(0);

            /* Default memory budget, larger tables spill to a temporary file */
            $temp = new SplTempFileObject();
            $temp->setCsvControl(...self::CSV_CONTROL);
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
        } finally {
            $this->file->flock(LOCK_UN);
        }
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
        if (! array_key_exists($field, $this->attr) && method_exists($this, $field)) {
            if (isset($this->relations[$field])) {
                return $this->relations[$field];
            }

            $relation = $this->$field();
            $class    = $relation::class;

            return $relation->relate['type'] === 'hasOne'
                ? $relation->first() ?? new $class()
                : $relation->get();
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
     * @return array
     */
    public function toArray(): array
    {
        return array_map(
            static fn (mixed $value) => is_object($value) ? $value->toArray() : $value,
            $this->attr
        );
    }
}
