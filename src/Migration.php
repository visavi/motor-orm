<?php

declare(strict_types=1);

namespace MotorORM;

use Closure;
use SplFileObject;
use SplTempFileObject;
use UnexpectedValueException;

/**
 * Migration
 */
class Migration
{
    protected array $columns = [];
    protected SplFileObject $file;

    public function __construct(public Builder $builder)
    {
        $this->file = $builder->open()->file();
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
        if ($this->existsColumn($column)) {
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

        if (! $this->existsColumn($column)) {
            throw new UnexpectedValueException(
                sprintf('%s() renaming undefined column. Column "%s" does not exist', __METHOD__, $column)
            );
        }

        if ($this->existsColumn($to)) {
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
        if (! $this->existsColumn($column)) {
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
        if (
            file_exists($this->builder->file()->getRealPath())
            && filesize($this->builder->file()->getRealPath()) !== 0
        ) {
            throw new UnexpectedValueException(
                sprintf('%s() creating table. Table "%s" already exists', __METHOD__, $this->builder->getTable())
            );
        }

        $closure($this);

        $columns = array_column($this->columns, 'name');
        $this->file->fputcsv($columns);
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
        if (! file_exists($this->builder->file()->getRealPath())) {
            throw new UnexpectedValueException(
                sprintf('%s() deleting table. Table "%s" does not exist', __METHOD__, $this->builder->getTable())
            );
        }

        unlink($this->builder->file()->getPathname());

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

        foreach ($this->columns as $column) {
            $column['curPos'] = array_search($column['name'], $this->builder->headers(), true);
            $column['newPos'] = array_search($column['before'] ?: $column['after'], $this->builder->headers(), true);

            $this->process(function ($temp, &$current) use ($column) {
                if ($column['delete']) {
                    $this->deleteColumn($current, $column);
                }  elseif ($column['rename']) {
                    $this->renameColumn($current, $column, $temp->key());
                } else {
                    $this->addColumn($current, $column, $temp->key());
                }
            });
        }

        $this->columns = [];

        return true;
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

            $closure($temp, $current);

            $this->file->fputcsv($current);
            $temp->next();
        }

        $this->file->flock(LOCK_UN);
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

    /**
     * Check exists column
     *
     * @param string $column
     *
     * @return bool
     */
    private function existsColumn(string $column): bool
    {
        return in_array($column,  $this->builder->headers(), true);
    }
}
