<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * Page navigation that never counted the rows
 *
 * Reading one row past the page is enough to know whether there is another one,
 * and that is all this navigation offers: a step back and a step forward. How
 * many pages there are in total nobody asked, so nobody had to read the table
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
class SimplePagination extends Paginator
{
    /**
     * @param array $items   the rows of the page
     * @param int   $limit   rows on a page
     * @param int   $page    the page being shown
     * @param bool  $hasMore whether a row past the page was there
     */
    public function __construct(
        array $items,
        int $limit,
        int $page,
        private readonly bool $hasMore,
    ) {
        parent::__construct($items, $limit, $page);
    }

    /**
     * Whether there is anything after this page
     *
     * @return bool
     */
    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    /**
     * The arrows, there is no way to know what the numbers would be
     *
     * @return Page[]
     */
    public function pages(): array
    {
        $pages = [];

        if (! $this->onFirstPage()) {
            $pages[] = $this->link($this->page - 1, '«');
        }

        if ($this->hasMore) {
            $pages[] = $this->link($this->page + 1, '»');
        }

        return $pages;
    }

    /**
     * Rows on the page being shown
     *
     * @return int
     */
    protected function rowsOnPage(): int
    {
        return $this->count();
    }
}
