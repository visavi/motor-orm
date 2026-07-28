<?php

declare(strict_types=1);

namespace MotorORM;

use ArrayIterator;
use Closure;
use Iterator;
use SplHeap;

/**
 * The order the rows of a read are put in
 *
 * Holding a table to sort it is only worth it when the whole order is wanted.
 * A read that stops after a page keeps just the rows the page would hold, so
 * what a sorted page costs follows the page and not the table
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final class Sorter
{
    /**
     * Rows a read may ask for before holding the whole order is cheaper
     *
     * Keeping a heap costs an insert per row, sorting once costs a sort. The
     * fewer rows are wanted, the more the heap wins, and past a page or two
     * the one sort at the end takes it back
     */
    private const int TAKE_LIMIT = 1000;

    /** Column name => the way it is sorted */
    private array $orders = [];

    /**
     * @param Table $table where a column name becomes a position
     */
    public function __construct(private readonly Table $table) {}

    /**
     * Add a column to sort by, after the ones already asked for
     *
     * @param string    $field
     * @param SortOrder $sort
     *
     * @return void
     */
    public function by(string $field, SortOrder $sort): void
    {
        $this->orders[$field] = $sort;
    }

    /**
     * Whether any order was asked for
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return ! $this->orders;
    }

    /**
     * Put the rows in the order that was asked for
     *
     * @param Iterator $iterator
     * @param int|null $take how many rows from the head are going to be read,
     *                       null when that is not known
     *
     * @return Iterator
     */
    public function sort(Iterator $iterator, ?int $take): Iterator
    {
        if ($this->isEmpty()) {
            return $iterator;
        }

        $comparer = $this->comparer();

        if ($take !== null && $take <= self::TAKE_LIMIT) {
            return $this->leading($iterator, $comparer, $take);
        }

        /* Nothing says how much of the order is wanted, so all of it is held at once */
        $sorted = new ArrayIterator(iterator_to_array($iterator));

        $sorted->uasort($comparer);

        return $sorted;
    }

    /**
     * How one row stands to another under the order asked for
     *
     * @return Closure
     */
    private function comparer(): Closure
    {
        /* Resolve the column positions once instead of on every comparison */
        $orders = [];
        foreach ($this->orders as $field => $sort) {
            $orders[$this->table->keyOf($field)] = $sort === SortOrder::Asc;
        }

        return static function (array $a, array $b) use ($orders): int {
            foreach ($orders as $key => $asc) {
                $retVal = $asc ? $a[$key] <=> $b[$key] : $b[$key] <=> $a[$key];

                if ($retVal !== 0) {
                    return $retVal;
                }
            }

            return 0;
        };
    }

    /**
     * The first rows of the order, without holding the rest
     *
     * Sorting still has to look at every row, but carrying every row is only
     * needed when every row is wanted. For a page it is enough to keep the
     * rows that would be on it: a heap holds the worst of them on top, so a
     * row that cannot beat it is dropped where it is read
     *
     * @param Iterator $iterator
     * @param Closure  $comparer
     * @param int      $take
     *
     * @return Iterator
     */
    private function leading(Iterator $iterator, Closure $comparer, int $take): Iterator
    {
        if ($take === 0) {
            return new ArrayIterator();
        }

        /* Rows the order cannot tell apart keep the order they were read in */
        $sequence = 0;
        $heap     = new class ($comparer) extends SplHeap {
            public function __construct(private readonly Closure $comparer) {}

            public function compare($value1, $value2): int
            {
                return ($this->comparer)($value1[1], $value2[1]) ?: $value1[0] <=> $value2[0];
            }
        };

        foreach ($iterator as $row) {
            $current = [$sequence++, $row];

            if ($heap->count() < $take) {
                $heap->insert($current);

                continue;
            }

            /* The top is the worst of the rows kept, so it is the one to give way */
            if ($heap->compare($current, $heap->top()) < 0) {
                $heap->extract();
                $heap->insert($current);
            }
        }

        /* A heap gives up its rows worst first, and the head of the order is wanted */
        $leading = [];
        foreach ($heap as [, $row]) {
            array_unshift($leading, $row);
        }

        return new ArrayIterator($leading);
    }
}
