<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * A row looked up by primary key without reading the table
 *
 * Keys are handed out one after another and a rewrite keeps the rows in the
 * order it read them, so a table normally lies sorted by its first column.
 * That is enough to halve the file instead of walking it: some twenty reads
 * settle a lookup that would otherwise go through every row
 *
 * Nothing is taken on trust. The rows a search lands on have to look like the
 * table they came from, and the row it ends at has to carry the very key that
 * was asked for. Anything else and the search says it does not know, leaving
 * the caller to read the table as it always did. A file sorted some other way,
 * a key that is not a number, a column count that does not add up: all of them
 * come out as "not found" rather than as a wrong answer
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final readonly class KeySearch
{
    /** Lines a probe steps over before it calls the search off */
    private const int STEPS = 8;

    /**
     * @param Table $table the file being searched
     */
    public function __construct(private Table $table) {}

    /**
     * The row carrying the key, if halving the file can tell
     *
     * @param string $key value of the first column
     *
     * @return array|null the row, or null when it is not there or cannot be told
     */
    public function row(string $key): ?array
    {
        /* Halving only means anything when the keys sort the way numbers do */
        if (! self::whole($key)) {
            return null;
        }

        $file   = $this->table->file();
        $width  = count($this->table->headersFrom($file));
        $target = (int) $key;

        /* The first line names the columns and takes no part in the search */
        if ($file->rowFrom(0) === false) {
            return null;
        }

        $low  = $file->tell();
        $high = $file->size();

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $found  = $this->probe($file, $middle, $width);

            /* Nothing to read on from here: the answer lies before it */
            if ($found === false) {
                $high = $middle;

                continue;
            }

            [, $row] = $found;

            if ((int) $row[0] < $target) {
                /* Carry on after the row just read, and never stand still */
                $low = max($file->tell(), $middle + 1);
            } else {
                $high = $middle;
            }
        }

        $found = $this->probe($file, $low, $width);

        if ($found === false) {
            return null;
        }

        [$start, $row] = $found;

        if ((int) $row[0] !== $target) {
            return null;
        }

        /* The row is only a row if it begins where a record does */
        return $file->startsRecord($start) ? $row : null;
    }

    /**
     * The first row from the byte on that looks like a row of this table
     *
     * A value may hold newlines, and then a good part of the file lies inside
     * a row rather than between two of them. What is read at such a byte is
     * the tail of a value and not a row, but the row that follows it is one,
     * so the search steps on instead of giving up. A few steps are enough for
     * anything short of a value made mostly of newlines, and giving up on that
     * only costs the reading the caller would have done anyway
     *
     * @param CsvFile $file  the table being searched
     * @param int     $from  byte to start looking at
     * @param int     $width column count of the table
     *
     * @return array{int, array}|false
     */
    private function probe(CsvFile $file, int $from, int $width): array|false
    {
        for ($step = 0; $step < self::STEPS; $step++) {
            $found = $file->rowFrom($from);

            if ($found === false) {
                return false;
            }

            [, $row] = $found;

            if ($this->readable($row, $width)) {
                return $found;
            }

            $from = $file->tell();
        }

        return false;
    }

    /**
     * Whether a row looks like one of this table
     *
     * A byte picked in the middle of the file may fall inside a value holding
     * a newline of its own, and what is read then is the tail of a row rather
     * than a row. Such a thing rarely has the columns of the table or a whole
     * number where the key belongs, and either is enough to call the search off
     *
     * @param array $row
     * @param int   $width column count of the table
     *
     * @return bool
     */
    private function readable(array $row, int $width): bool
    {
        return count($row) === $width && self::whole((string) $row[0]);
    }

    /**
     * Whether the value is nothing but digits
     *
     * Written without ctype, which is an extension the orm does without: the
     * check happens some twenty times per lookup and a pattern costs nothing
     * at that count
     *
     * @param string $value
     *
     * @return bool
     */
    private static function whole(string $value): bool
    {
        return $value !== '' && preg_match('/^\d++$/D', $value) === 1;
    }
}
