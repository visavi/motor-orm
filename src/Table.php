<?php

declare(strict_types=1);

namespace MotorORM;

use CallbackFilterIterator;
use Closure;
use Iterator;
use LimitIterator;
use SplFileObject;
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

    private ?SplFileObject $file = null;

    /**
     * @param Model $model the table being read
     */
    public function __construct(private readonly Model $model) {}

    /**
     * The table file, opened when something is finally read from it
     *
     * Collecting conditions touches no file, so a query that is never run and
     * a group of conditions built inside a closure both stay off the disk
     *
     * @return SplFileObject
     */
    public function file(): SplFileObject
    {
        return $this->file ??= $this->model->file();
    }

    /**
     * Column names
     *
     * @return array
     */
    public function headers(): array
    {
        if ($this->headers === null) {
            $file = $this->file();
            $file->seek(0);

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
     * A fresh walk every time, so asking the same query twice answers the
     * same way and nothing is left half read behind
     *
     * @return Iterator
     */
    public function records(): Iterator
    {
        $file = $this->file();
        $file->rewind();

        /* The first line names the columns, and a trailing newline is no row */
        return new CallbackFilterIterator(
            new LimitIterator($file, 1),
            static fn ($current) => $current !== [null]
        );
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
    public function replace(Closure $writer): void
    {
        $path     = $this->model->getPath();
        $tempPath = $path . '.tmp';
        $lock     = $this->lock();

        try {
            $target = new SplFileObject($tempPath, 'wb');
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
     * Let go of the file that was being read
     *
     * A write replaces the table by a rename, so the open handle points at a
     * file that is no longer the table. The next read opens it again
     *
     * @return void
     */
    public function close(): void
    {
        $this->file       = null;
        $this->headers    = null;
        $this->headerKeys = [];
    }
}
