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
    protected string $column;
    protected string $renameColumn;
    protected mixed $position;
    protected mixed $default = null;
    protected mixed $after = false;
    protected SplFileObject $file;

    public function __construct(public Builder $builder)
    {
        $this->file = $builder->open()->file();
    }

    /**
     * Set column
     *
     * @param string $column
     *
     * @return $this
     */
    public function column(string $column): static
    {
        $this->column = $column;
        $this->position = array_search($column, $this->builder->headers(), true);

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
        $this->default = $default;

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
        $this->after = array_search($column, $this->builder->headers(), true);

        return $this;
    }

    /**
     * Set rename column
     *
     * @param string $column
     *
     * @return $this
     */
    public function to(string $column): static
    {
        $this->renameColumn = $column;

        return $this;
    }

    /**
     * Rename column
     *
     * @return string;
     */
    public function rename(): string
    {
        if (! $this->existsColumn($this->column)) {
            throw new UnexpectedValueException(
                sprintf('%s() renaming undefined column. Column "%s" does not exist', __METHOD__, $this->column)
            );
        }

        if ($this->existsColumn($this->renameColumn)) {
            throw new UnexpectedValueException(
                sprintf('%s() renaming an existing column. Column "%s" already exist', __METHOD__, $this->renameColumn)
            );
        }

        $this->process(function ($temp, &$current) {
            if ($temp->key() === 0) {
                $current[$this->position] = $this->renameColumn;
            }
        });

        return sprintf('Column "%s" successfully renamed to "%s"!', $this->column, $this->renameColumn);
    }


    /**
     * Delete column
     *
     * @return string
     */
    public function delete(): string
    {
        if (! $this->existsColumn($this->column)) {
            throw new UnexpectedValueException(
                sprintf('%s() deleting undefined column. Column "%s" does not exist', __METHOD__, $this->column)
            );
        }

        $this->process(function ($temp, &$current) {
            $this->deleteColumn($current, $this->position);
        });

        return sprintf('Column "%s" successfully deleted!', $this->column);
    }

    /**
     * Create column
     *
     * @return string
     */
    public function create(): string
    {
        if ($this->existsColumn($this->column)) {
            throw new UnexpectedValueException(
                sprintf('%s() adding an existing column. Column "%s" already exist', __METHOD__, $this->column)
            );
        }

        $this->process(function ($temp, &$current) {
            if ($temp->key() === 0) {
                $this->addColumn($current, $this->column, $this->after);
            } else {
                $this->addColumn($current, $this->default, $this->after);
            }
        });

        return sprintf('Column "%s" successfully added!', $this->column);
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
     * Add column after position
     *
     * @param array $array
     * @param mixed $value
     * @param mixed $position
     *
     * @return void
     */
    private function addColumn(array &$array, mixed $value, mixed $position = false): void
    {
        if ($position !== false) {
            array_splice($array, $position + 1, 0, [$value]);
        } else {
            $array[] = $value;
        }
    }

    /**
     * Delete column from position
     *
     * @param array $array
     * @param mixed $position
     *
     * @return void
     */
    private function deleteColumn(array &$array, mixed $position = false): void
    {
        if ($position !== false) {
            unset($array[$position]);
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
        return in_array($column, $this->builder->headers(), true);
    }
}
