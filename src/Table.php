<?php

declare(strict_types=1);

namespace MotorORM;

use Closure;
use Iterator;
use Throwable;
use UnexpectedValueException;

/**
 * The file a model stands for
 *
 * Everything that touches the disk lives here: opening the table, reading its
 * column names and rows, taking the write lock and putting a new file in place
 * of the old one. What the rows mean is the business of Query
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final class Table
{
    /** Column names, read from the first line once the table is needed */
    private ?array $headers = null;

    /** Column name => position, resolved along with the headers */
    private array $headerKeys = [];

    /**
     * @param Model $model the table being read
     */
    public function __construct(private readonly Model $model) {}

    /**
     * The table file, opened when something is finally read from it
     *
     * Collecting conditions touches no file, so a query that is never run and
     * a group of conditions built inside a closure both stay off the disk. A
     * handle is never held on to: a write replaces the table by a rename, and
     * one kept open would point at a file that is no longer it
     *
     * @return CsvFile
     */
    public function file(): CsvFile
    {
        return $this->model->file();
    }

    /**
     * Column names
     *
     * Read through a handle of its own: a column name is asked for while the
     * rows are already going by, and seeking the handle they come from would
     * hand the same row out twice
     *
     * @return array
     */
    public function headers(): array
    {
        if ($this->headers === null) {
            $file = $this->file();

            $this->headers    = $file->current() ?: [];
            $this->headerKeys = array_flip($this->headers);
        }

        return $this->headers;
    }

    /**
     * Position of a column in a row
     *
     * @param string $field
     *
     * @return int
     */
    public function keyOf(string $field): int
    {
        $this->headers();

        $key = $this->headerKeys[$field] ?? false;

        if ($key === false) {
            throw new UnexpectedValueException(sprintf('%s() called undefined column. Column "%s" does not exist', __METHOD__, $field));
        }

        return $key;
    }

    /**
     * The rows of the table, header aside
     *
     * A walk of its own every time, down to the handle it reads through: the
     * same query answers again and again, and a walk started while another is
     * going on would otherwise wind the file back under it
     *
     * @return Iterator
     */
    public function records(): Iterator
    {
        /* The first line names the columns, and a trailing newline is no row */
        return $this->file()->rows(1);
    }

    /**
     * Rewrite the table row by row
     *
     * The rows go to a sibling file that replaces the table in one atomic
     * step, so a reader never sees a half written table and a failure
     * leaves the original untouched
     *
     * @param Closure $closure called with the record, the file being written and the line it was read from
     *
     * @return void
     */
    public function rewrite(Closure $closure): void
    {
        $this->replace(function (CsvFile $target) use ($closure) {
            foreach ($this->file()->rows() as $line => $current) {
                $closure($current, $target, $line);
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
    public function replace(Closure $writer): void
    {
        $path     = $this->model->getPath();
        $tempPath = $path . '.tmp';
        $lock     = $this->lock();

        try {
            $target = new CsvFile($tempPath, 'wb', ...Model::CSV_CONTROL);

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

        $this->close();
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
    public function lock()
    {
        while (true) {
            $handle = @fopen($this->model->getPath(), 'rb');

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
     * Forget what was read about the table
     *
     * A migration may leave it with other columns than the ones read before,
     * so the names are looked up again when they are next asked for
     *
     * @return void
     */
    public function close(): void
    {
        $this->headers    = null;
        $this->headerKeys = [];
    }
}
