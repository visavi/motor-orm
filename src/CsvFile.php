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
    /** How much of the file is read at once when it is only being counted */
    private const int CHUNK = 1 << 16;

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
     * How many rows the file holds
     *
     * The rows are counted, not read: a count has no use for the values, and
     * building an array out of every line is what reading one mostly costs.
     * A line begins a row unless a value left open on an earlier line is still
     * running through it, which the quotes tell — one opens a value, the next
     * closes it, and a quote inside a value is written twice
     *
     * That is the format this reads and writes. A quote loose in a value that
     * was never opened is not it, and such a file is counted the way the rules
     * say rather than the way it was meant
     *
     * @param int $skip lines to leave out at the head of the file
     *
     * @return int
     */
    public function countRows(int $skip = 0): int
    {
        if (! $this->readable) {
            return 0;
        }

        rewind($this->handle);

        $rows = 0;
        $line = 0;
        $open = false;

        while (($text = fgets($this->handle)) !== false) {
            /* A line the value of another row runs through is no row of its own */
            if (! $open && $line++ >= $skip && rtrim($text, "\r\n") !== '') {
                $rows++;
            }

            if ($this->enclosure !== '' && substr_count($text, $this->enclosure) % 2 === 1) {
                $open = ! $open;
            }
        }

        return $rows;
    }

    /**
     * The first whole row that starts at the byte or after it
     *
     * A byte picked at random usually falls in the middle of a line, and half
     * a line is no row: the rest of it is dropped and the one that follows is
     * read instead. Blank lines are stepped over, as everywhere else
     *
     * Handing out where the row began is the point of this: a search that
     * halves the file has to know which byte to carry on from
     *
     * The handle is moved, so a walk of the rows going on at the same time
     * would lose its place. Nothing here starts one
     *
     * @param int $position byte to start looking at
     *
     * @return array{int, array}|false the byte the row began at and the row
     */
    public function rowFrom(int $position): array|false
    {
        if (! $this->readable) {
            return false;
        }

        if ($position <= 0) {
            rewind($this->handle);
        } else {
            /* Standing one byte back tells a line boundary from a line cut in
               half: reading to the end of a line that is already over takes
               the newline alone and drops nothing */
            fseek($this->handle, $position - 1);
            fgets($this->handle);
        }

        while (true) {
            $start = ftell($this->handle);
            $row   = fgetcsv($this->handle, 0, $this->separator, $this->enclosure, $this->escape);

            if ($row === false) {
                return false;
            }

            if ($row !== [null]) {
                return [$start, $row];
            }
        }
    }

    /**
     * Whether a record begins at the byte
     *
     * A value may hold a newline of its own, and then the line after it looks
     * like a row without being one. Telling the two apart takes the whole of
     * the file up to the byte: a quote opens a value and the next one closes
     * it, a quote inside a value is written twice, so the quotes before a byte
     * that stands outside every value are an even number of them
     *
     * The bytes are only counted, not parsed, which is some fifty times less
     * work than reading the rows they make up
     *
     * A file that is quoted wrong answers no, and a caller that has to be sure
     * falls back on reading the table, never on a row that is not one
     *
     * @param int $position byte the record would begin at
     *
     * @return bool
     */
    public function startsRecord(int $position): bool
    {
        if ($position === 0) {
            return true;
        }

        if (! $this->readable || $this->enclosure === '' || $position < 0) {
            return false;
        }

        /* A record begins where a line does */
        fseek($this->handle, $position - 1);

        if (fread($this->handle, 1) !== "\n") {
            return false;
        }

        rewind($this->handle);

        $quotes = 0;
        $left   = $position;

        while ($left > 0) {
            $chunk = fread($this->handle, min(self::CHUNK, $left));

            if ($chunk === false || $chunk === '') {
                return false;
            }

            $quotes += substr_count($chunk, $this->enclosure);
            $left   -= strlen($chunk);
        }

        return $quotes % 2 === 0;
    }

    /**
     * Byte the file stands at
     *
     * @return int
     */
    public function tell(): int
    {
        return ftell($this->handle);
    }

    /**
     * Size of the file in bytes
     *
     * @return int
     */
    public function size(): int
    {
        $stat = fstat($this->handle);

        return $stat['size'] ?? 0;
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
