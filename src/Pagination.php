<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * Page navigation that knows how many rows there are
 *
 * Knowing the total is what buys the numbered links, and it is paid for by
 * counting the rows of the table. When the numbers are not worth the reading,
 * there is SimplePagination
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
class Pagination extends PagedCollection
{
    /** Total rows behind all the pages */
    private(set) int $total;

    /** Pages shown on either side of the current one */
    private(set) int $crumbs = 1;

    /**
     * @param array $items the rows of the page
     * @param int   $total rows behind all the pages
     * @param int   $limit rows on a page
     * @param int   $page  the page to show, clamped to the available range
     */
    public function __construct(array $items, int $total, int $limit = 10, int $page = 1)
    {
        $this->total = max(0, $total);

        parent::__construct($items, $limit, $page);
    }

    /**
     * Get total items
     *
     * @return int
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Number of the last page, one when there is nothing to show
     *
     * @return int
     */
    public function lastPage(): int
    {
        return self::lastPageOf($this->total, $this->limit);
    }

    /**
     * Number of the last page of a table not yet read
     *
     * A query has to know which rows to skip before it reads them, so the
     * arithmetic lives where it can be asked for without a page of rows
     *
     * @param int $total
     * @param int $limit
     *
     * @return int
     */
    public static function lastPageOf(int $total, int $limit): int
    {
        return max(1, (int) ceil(max(0, $total) / max(1, $limit)));
    }

    /**
     * Whether there is anything after this page
     *
     * @return bool
     */
    public function hasMorePages(): bool
    {
        return $this->page < $this->lastPage();
    }

    /**
     * Whether there is more than one page
     *
     * @return bool
     */
    public function hasPages(): bool
    {
        return $this->lastPage() > 1;
    }

    /**
     * Url of any page, not only of the ones on show
     *
     * @param int $page clamped to the available range
     *
     * @return string
     */
    public function url(int $page): string
    {
        return $this->buildUrl(min(max(1, $page), $this->lastPage()));
    }

    /**
     * Show more pages on either side of the current one
     *
     * @param int $crumbs
     *
     * @return $this
     */
    public function onEachSide(int $crumbs): static
    {
        $this->crumbs = max(0, $crumbs);

        return $this;
    }

    /**
     * The entries of the navigation
     *
     * @return Page[]
     */
    public function pages(): array
    {
        if (! $this->total) {
            return [];
        }

        $pages      = [];
        $lastPage   = $this->lastPage();
        $indexFirst = max($this->page - $this->crumbs, 1);
        $indexLast  = min($this->page + $this->crumbs, $lastPage);

        if ($this->page !== 1) {
            $pages[] = $this->link($this->page - 1, '«');
        }

        if ($this->page > $this->crumbs + 1) {
            $pages[] = $this->link(1);

            if ($this->page !== $this->crumbs + 2) {
                $pages[] = Page::separator();
            }
        }

        for ($i = $indexFirst; $i <= $indexLast; $i++) {
            $pages[] = $i === $this->page ? Page::current($i) : $this->link($i);
        }

        if ($this->page < $lastPage - $this->crumbs) {
            if ($this->page !== $lastPage - $this->crumbs - 1) {
                $pages[] = Page::separator();
            }

            $pages[] = $this->link($lastPage);
        }

        if ($this->page !== $lastPage) {
            $pages[] = $this->link($this->page + 1, '»');
        }

        return $pages;
    }

    /**
     * Keep the page within the available range
     *
     * @param int $page
     *
     * @return int
     */
    protected function clamp(int $page): int
    {
        return min($page, $this->lastPage());
    }

    /**
     * Rows on the page being shown
     *
     * @return int
     */
    protected function rowsOnPage(): int
    {
        return max(0, min($this->limit, $this->total - $this->offset));
    }
}
