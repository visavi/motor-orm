<?php

declare(strict_types=1);

namespace MotorORM;

use Closure;
use SplFileObject;
use UnexpectedValueException;

/**
 * Migration
 */
class Migration
{
    protected array $columns = [];
    protected ?SplFileObject $file = null;

    public function __construct(public Model $model)
    {
    }

    /**
     * Open the table on first use, which requires it to exist. Creating
     * one goes through createTable().
     *
     * @return SplFileObject
     */
    protected function file(): SplFileObject
    {
        return $this->file ??= $this->model->file();
    }

    /**
     * Set create column
     *
     * @param string $column
     *
     * @return $this
     */
    public function create(string $column): static
    {
        if ($this->hasColumn($column)) {
            throw new UnexpectedValueException(
                sprintf('%s() adding an existing column. Column "%s" already exists', __METHOD__, $column)
            );
        }

        $this->columns[$column] = [
            'name'    => $column,
            'default' => null,
            'before'  => false,
            'after'   => false,
            'create'  => true,
            'rename'  => false,
            'delete'  => false,
        ];

        return $this;
    }

    /**
     * Set default value
     *
     * @param mixed $default
     *
     * @return $this
     */
    public function default(mixed $default): static
    {
        $lastColumn = array_key_last($this->columns);
        $this->columns[$lastColumn]['default'] = $default;

        return $this;
    }

    /**
     * Set after column
     *
     * @param string $column
     *
     * @return $this
     */
    public function after(string $column): static
    {
        $lastColumn = array_key_last($this->columns);
        $this->columns[$lastColumn]['after'] = $column;

        return $this;
    }

    /**
     * Set before column
     *
     * @param string $column
     *
     * @return $this
     */
    public function before(string $column): static
    {
        $lastColumn = array_key_last($this->columns);
        $this->columns[$lastColumn]['before'] = $column;

        return $this;
    }

    /**
     * Set rename column
     *
     * @param string $column
     * @param string $to
     *
     * @return $this
     */
    public function rename(string $column, string $to): static
    {

        if (! $this->hasColumn($column)) {
            throw new UnexpectedValueException(
                sprintf('%s() renaming undefined column. Column "%s" does not exist', __METHOD__, $column)
            );
        }

        if ($this->hasColumn($to)) {
            throw new UnexpectedValueException(
                sprintf('%s() renaming an existing column. Column "%s" already exist', __METHOD__, $to)
            );
        }

        $this->columns[$column] = [
            'name'   => $column,
            'to'     => $to,
            'before' => false,
            'after'  => false,
            'rename' => true,
            'delete' => false,
        ];

        return $this;
    }

    /**
     * Set delete column
     *
     * @param string $column
     *
     * @return $this
     */
    public function delete(string $column): static
    {
        if (! $this->hasColumn($column)) {
            throw new UnexpectedValueException(
                sprintf('%s() deleting undefined column. Column "%s" does not exist', __METHOD__, $column)
            );
        }

        $this->columns[$column] = [
            'name'   => $column,
            'delete' => true,
            'before' => false,
            'after'  => false,
        ];

        return $this;
    }

    /**
     * Create table
     *
     * @param Closure $closure
     *
     * @return bool
     */
    public function createTable(Closure $closure): bool
    {
        if ($this->hasTable()) {
            throw new UnexpectedValueException(
                sprintf('%s() creating table. Table "%s" already exists', __METHOD__, $this->model->getTable())
            );
        }

        $closure($this);

        $columns = array_column($this->columns, 'name');

        $file = $this->file = $this->model->createFile();
        chmod($this->model->getPath(), 0666);

        $file->fputcsv($columns);
        $this->columns = [];

        return true;
    }

    /**
     * Delete table
     *
     * @return bool
     */
    public function deleteTable(): bool
    {
        if (! $this->hasTable()) {
            throw new UnexpectedValueException(
                sprintf('%s() deleting table. Table "%s" does not exist', __METHOD__, $this->model->getTable())
            );
        }

        unlink($this->model->getPath());
        $this->file = null;

        return true;
    }

    /**
     * Change table
     *
     * @param Closure $closure
     *
     * @return bool
     */
    public function changeTable(Closure $closure): bool
    {
        $closure($this);

        if (! $this->columns) {
            return true;
        }

        /*
         * Positions are resolved against the headers as every change is
         * applied, so that a column added earlier is visible to the next one.
         * Replaying that plan row by row rewrites the table in a single pass.
         */
        $headers = $this->headers();
        $plan    = [];

        foreach ($this->columns as $column) {
            $column['curPos'] = array_search($column['name'], $headers, true);
            $column['newPos'] = array_search($column['before'] ?: $column['after'], $headers, true);

            $plan[]  = $column;
            $headers = $this->applyColumn($headers, $column, 0);
        }

        $this->process(function ($temp, &$current) use ($plan) {
            $line = $temp->key();

            foreach ($plan as $column) {
                $current = $this->applyColumn($current, $column, $line);
            }
        });

        $this->columns = [];

        return true;
    }

    /**
     * Apply a single column change to one record
     *
     * @param array $record
     * @param array $column
     * @param int   $line
     *
     * @return array
     */
    private function applyColumn(array $record, array $column, int $line): array
    {
        if ($column['delete']) {
            $this->deleteColumn($record, $column);

            /* Close the gap left behind, the next change counts positions */
            return array_values($record);
        }

        if ($column['rename']) {
            $this->renameColumn($record, $column, $line);

            return $record;
        }

        $this->addColumn($record, $column, $line);

        return $record;
    }

    /**
     * Has column
     *
     * @param string $column
     *
     * @return bool
     */
    public function hasColumn(string $column): bool
    {
        return in_array($column, $this->headers(), true);
    }

    /**
     * Has table
     *
     * @return bool
     */
    public function hasTable(): bool
    {
        $path = $this->model->getPath();

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Headers of the table, opening it if that has not happened yet
     *
     * @return array
     */
    private function headers(): array
    {
        if (! $this->hasTable()) {
            return [];
        }

        $this->file();

        return $this->model::query()->headers();
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
        $this->model::query()->rewrite(static function (array &$current, SplFileObject $target, SplFileObject $source) use ($closure) {
            $closure($source, $current);

            $target->fputcsv($current);
        });

        /* The table file was replaced, the handle we were holding is stale */
        $this->file = null;
    }

    /**
     * Add column
     *
     * @param array $array
     * @param array $column
     * @param int   $line
     *
     * @return void
     */
    private function addColumn(array &$array, array $column, int $line): void
    {
        $columnValue = $line === 0 ? $column['name'] : $column['default'];

        if ($column['newPos'] !== false) {
            $position = $column['before'] ? $column['newPos'] : $column['newPos'] + 1;
            array_splice($array, $position, 0, [$columnValue]);
        } else {
            $array[] = $columnValue;
        }
    }

    /**
     * Rename column
     *
     * @param array $array
     * @param array $column
     * @param int   $line
     *
     * @return void
     */
    private function renameColumn(array &$array, array $column, int $line): void
    {
        if ($line === 0 && $column['curPos'] !== false) {
            $array[$column['curPos']] = $column['to'];
        }
    }

    /**
     * Delete column from position
     *
     * @param array $array
     * @param array $column
     *
     * @return void
     */
    private function deleteColumn(array &$array, array $column): void
    {
        if ($column['curPos'] !== false) {
            unset($array[$column['curPos']]);
        }
    }
}
