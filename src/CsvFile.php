<?php

declare(strict_types=1);

namespace MotorORM;

use Generator;
use RuntimeException;
use SeekableIterator;

/**
 * A csv file, read row by row
 *
 * Stands in for SplFileObject with its csv flags, which php 8.6 deprecates.
 * The parsing is still the one of the engine: fgetcsv and fputcsv over a plain
 * handle are not going anywhere, only the methods of SplFileObject are
 *
 * A row is an array of the values of a line, and the key of the iteration is
 * the number of that line, counted from zero, so that the first one is the
 * header of the table
 *
 * @implements SeekableIterator<int, array|false>
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final class CsvFile implements SeekableIterator
{
    /** @var resource */
    private $handle;

    /** Whether the mode the file was opened in allows reading it */
    private readonly bool $readable;

    /** Line the iteration stands at, the header being zero */
    private int $line = 0;

    /** Row the iteration stands at, false once the file is through */
    private array|false $current = false;

    /**
     * @param string $path      file to open
     * @param string $mode      mode to open it in, as fopen takes it
     * @param string $separator what stands between two values
     * @param string $enclosure what a value is wrapped in when it has to be
     * @param string $escape    escaping character, empty for none
     */
    public function __construct(
        private readonly string $path,
        string $mode = 'rb',
        private readonly string $separator = ',',
        private readonly string $enclosure = '"',
        private readonly string $escape = '',
    ) {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException(sprintf('%s(): unable to open file: %s', __METHOD__, $path));
        }

        $this->handle   = $handle;
        $this->readable = str_contains($mode, 'r') || str_contains($mode, '+');

        $this->rewind();
    }

    /**
     * Close the handle once nothing holds the file any more
     */
    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * Path the file was opened by
     *
     * @return string
     */
    public function getPathname(): string
    {
        return $this->path;
    }

    /**
     * Write a row to the end of the file
     *
     * @param array $fields values of the row
     *
     * @return int|false bytes written
     */
    public function fputcsv(array $fields): int|false
    {
        return fputcsv($this->handle, $fields, $this->separator, $this->enclosure, $this->escape);
    }

    /**
     * The rows of the file, from the first line wanted to the last
     *
     * A walk of its own, apart from the one the iterator methods do: reading
     * a row through them costs a call for every method of the interface, and
     * a table is read row by row often enough for that to be what is paid the
     * most. Empty lines are not rows and never come out of here
     *
     * @param int $skip lines to leave out at the head of the file
     *
     * @return Generator<int, array> line number => row
     */
    public function rows(int $skip = 0): Generator
    {
        rewind($this->handle);

        $line = 0;

        while (($row = fgetcsv($this->handle, 0, $this->separator, $this->enclosure, $this->escape)) !== false) {
            /* A trailing newline is no row, and neither is a blank line */
            if ($line++ < $skip || $row === [null]) {
                continue;
            }

            yield $line - 1 => $row;
        }
    }

    /**
     * Row the iteration stands at
     *
     * @return array|false
     */
    public function current(): array|false
    {
        return $this->current;
    }

    /**
     * Number of the line the iteration stands at
     *
     * @return int
     */
    public function key(): int
    {
        return $this->line;
    }

    /**
     * Read the line that follows
     *
     * @return void
     */
    public function next(): void
    {
        $this->line++;
        $this->read();
    }

    /**
     * Start the file over
     *
     * @return void
     */
    public function rewind(): void
    {
        if (! $this->readable) {
            return;
        }

        rewind($this->handle);

        $this->line = 0;
        $this->read();
    }

    /**
     * Whether there is a row to read
     *
     * @return bool
     */
    public function valid(): bool
    {
        return $this->current !== false;
    }

    /**
     * Stand at a line of the file
     *
     * The lines of a csv are of no fixed length and a value may hold a newline
     * of its own, so the only way to a line is through the ones before it
     *
     * @param int $offset line to stand at, counted from zero
     *
     * @return void
     */
    public function seek(int $offset): void
    {
        if ($offset < $this->line) {
            $this->rewind();
        }

        while ($this->line < $offset && $this->valid()) {
            $this->next();
        }
    }

    /**
     * Read one line into the current row
     *
     * @return void
     */
    private function read(): void
    {
        $this->current = fgetcsv($this->handle, 0, $this->separator, $this->enclosure, $this->escape);
    }
}
