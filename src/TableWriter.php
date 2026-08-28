<?php

declare(strict_types=1);

namespace MotorORM;

use Iterator;
use UnexpectedValueException;

/**
 * Everything a query does to a table rather than with it
 *
 * A row is added to the end of the file, and anything else replaces the whole
 * of it. Either way the rows go by one at a time and are written as they are
 * read, so what a write costs does not follow the size of the table
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final readonly class TableWriter
{
    /**
     * @param Table        $table      the file being written
     * @param RowMapper $mapper     values in, strings out
     * @param Conditions   $conditions which rows a write is meant for
     */
    public function __construct(
        private Table        $table,
        private RowMapper $mapper,
        private Conditions   $conditions,
    ) {}

    /**
     * Add a row to the end of the table
     *
     * @param array $values column name => value
     *
     * @return array the values as they were written, the primary key among them
     */
    public function insert(array $values): array
    {
        $primary  = $this->primaryKey();
        $fields   = array_fill_keys($this->table->headers(), '');
        $diffKeys = array_diff_key($values, $fields);

        if ($diffKeys) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, key($diffKeys)));
        }

        $lock = $this->table->lock();

        try {
            /* Another writer may have replaced the file, read it as it is now */
            $this->table->close();

            [$maxId, $taken] = $this->scanKeys($this->table->records(), (string) ($values[$primary] ?? ''));

            if ($taken) {
                throw new UnexpectedValueException(sprintf('%s() duplicate entry. Column "%s" with the value "%s" already exists', __METHOD__, $primary, $values[$primary]));
            }

            if (! isset($values[$primary])) {
                $values[$primary] = $this->nextPrimaryKey($maxId);
            }

            $current = array_replace($fields, $values);
            $current = $this->mapper->write($current);
            $this->table->file()->fputcsv($current);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $values;
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
        $key    = (string) ($attr[$this->primaryKey()] ?? '');

        $this->table->rewrite(function (array &$current, CsvFile $target) use (&$result, $attr, $key) {
            if ((string) $current[0] === $key) {
                $current = $this->mapper->write($attr);

                $result = true;
            }

            $target->fputcsv($current);
        });

        return $result;
    }

    /**
     * Write the same values into every row the conditions ask for
     *
     * @param array $values column name => value
     *
     * @return int affected rows
     */
    public function update(array $values): int
    {
        $diffKeys = array_diff_key($values, array_flip($this->table->headers()));

        if ($diffKeys) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, key($diffKeys)));
        }

        $affectedRows = 0;
        $reader       = $this->mapper->reader();

        /* A row is written as it is read, so nothing of the table is held on to */
        $this->table->rewrite(function (array &$current, CsvFile $target, int $line) use ($reader, $values, &$affectedRows) {
            if ($this->matching($current, $line)) {
                $affectedRows++;
                $current = array_replace($reader($current), $values);
                $current = $this->mapper->write($current);
            }

            $target->fputcsv($current);
        });

        return $affectedRows;
    }

    /**
     * Leave out every row the conditions ask for
     *
     * @return int affected rows
     */
    public function delete(): int
    {
        $affectedRows = 0;

        $this->table->rewrite(function (array $current, CsvFile $target, int $line) use (&$affectedRows) {
            if ($this->matching($current, $line)) {
                $affectedRows++;
            } else {
                $target->fputcsv($current);
            }
        });

        return $affectedRows;
    }

    /**
     * Leave the table with its column names and nothing else
     *
     * @return void
     */
    public function truncate(): void
    {
        /* Only the column names survive, the rest of the table is never read */
        $headers = $this->table->headers();

        $this->table->replace(static function (CsvFile $target) use ($headers) {
            $target->fputcsv($headers);
        });
    }

    /**
     * Whether a raw row is one a write is meant for
     *
     * A query without conditions means every row, the same way filtering
     * lets the whole table through
     *
     * @param array $record raw row of the file
     * @param int   $line   the line it was read from
     *
     * @return bool
     */
    private function matching(array $record, int $line): bool
    {
        /* A rewrite is handed every line of the file, and the first one names the columns */
        if ($line === 0) {
            return false;
        }

        return $this->conditions->isEmpty() || $this->conditions->match($record, $this->table);
    }

    /**
     * Look through the keys of the table without holding them
     *
     * A row is added knowing two things: the highest key the table has taken
     * and whether the key it comes with is one of them. Both are answered by
     * the rows as they go by, so writing to a table does not cost what reading
     * all of it would
     *
     * @param Iterator $iterator
     * @param string   $wanted the key of the row being added, empty when it has none
     *
     * @return array{0: string|null, 1: bool} the highest key read and whether $wanted is taken
     */
    private function scanKeys(Iterator $iterator, string $wanted): array
    {
        $key = $this->table->keyOf($this->primaryKey());

        $maxId = null;
        $taken = false;

        foreach ($iterator as $record) {
            $id = (string) ($record[$key] ?? '');

            if ($id === '') {
                continue;
            }

            if ($id === $wanted) {
                $taken = true;
            }

            if ($maxId === null || $id > $maxId) {
                $maxId = $id;
            }
        }

        return [$maxId, $taken];
    }

    /**
     * Continue a numeric primary key from the highest one already taken
     *
     * @param string|null $maxId the highest key of the table, null when it has no rows
     *
     * @return int|float the next free key
     */
    private function nextPrimaryKey(?string $maxId): int|float
    {
        if ($maxId === null) {
            return 1;
        }

        /* Keys are read as raw strings, only a numeric one can be continued */
        if (! is_numeric($maxId)) {
            throw new UnexpectedValueException(sprintf('%s() no unique ID assigned. Column "%s" cannot be generated', __METHOD__, $this->primaryKey()));
        }

        return ++$maxId;
    }

    /**
     * The column a row is known by
     *
     * @return string|null
     */
    private function primaryKey(): ?string
    {
        return $this->table->headers()[0] ?? null;
    }
}
