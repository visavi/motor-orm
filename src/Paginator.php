<?php

declare(strict_types=1);

namespace MotorORM;

use InvalidArgumentException;

/**
 * What every page navigation knows
 *
 * A paginator is the page: it holds the rows and is walked over like any other
 * collection, and it knows where that page stands among the rest. Where the
 * page number came from is none of its business
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
abstract class Paginator extends Collection
{
    /** Rows on a page */
    protected(set) int $limit;

    /** The page being shown */
    protected(set) int $page;

    /** Rows to skip to reach the page */
    protected(set) int $offset;

    /** Name of the page parameter in the built urls */
    protected(set) string $pageName = 'page';

    /** Path the links point at, relative to the current one when it is null */
    protected(set) ?string $path = null;

    /** Query parameters every link carries along */
    protected(set) array $appends = [];

    /** Template the links are rendered with */
    protected string $viewPath = __DIR__ . '/views/bootstrap5.php';

    /**
     * @param array $items the rows of the page
     * @param int   $limit rows on a page
     * @param int   $page  the page to show
     */
    protected function __construct(array $items, int $limit, int $page)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(
                sprintf('%s() a page has to hold at least one row, %d given', static::class, $limit)
            );
        }

        $this->limit  = $limit;
        $this->page   = $this->clamp(max(1, $page));
        $this->offset = $this->page * $this->limit - $this->limit;

        parent::__construct($items);
    }

    /**
     * The entries of the navigation
     *
     * The rows of the page are the collection itself, these are the links
     * under it
     *
     * @return Page[]
     */
    abstract public function pages(): array;

    /**
     * Whether there is anything after this page
     *
     * @return bool
     */
    abstract public function hasMorePages(): bool;

    /**
     * Rows on the page being shown
     *
     * @return int
     */
    abstract protected function rowsOnPage(): int;

    /**
     * Get current page
     *
     * @return int
     */
    public function currentPage(): int
    {
        return $this->page;
    }

    /**
     * Rows on a page
     *
     * @return int
     */
    public function perPage(): int
    {
        return $this->limit;
    }

    /**
     * Number of the first row of the page among all the rows
     *
     * @return int|null null when the page holds nothing
     */
    public function firstItem(): ?int
    {
        return $this->rowsOnPage() ? $this->offset + 1 : null;
    }

    /**
     * Number of the last row of the page among all the rows
     *
     * @return int|null null when the page holds nothing
     */
    public function lastItem(): ?int
    {
        return $this->rowsOnPage() ? $this->offset + $this->rowsOnPage() : null;
    }

    /**
     * Whether the first page is the one being shown
     *
     * @return bool
     */
    public function onFirstPage(): bool
    {
        return $this->page === 1;
    }

    /**
     * Whether the last page is the one being shown
     *
     * @return bool
     */
    public function onLastPage(): bool
    {
        return ! $this->hasMorePages();
    }

    /**
     * Whether there is more than one page
     *
     * @return bool
     */
    public function hasPages(): bool
    {
        return $this->hasMorePages() || ! $this->onFirstPage();
    }

    /**
     * Url of a page
     *
     * @param int $page
     *
     * @return string
     */
    public function url(int $page): string
    {
        return $this->buildUrl(max(1, $page));
    }

    /**
     * Get rendered links
     *
     * @param string|null $view a template for this call, the built-in one by default
     *
     * @return string
     */
    public function links(?string $view = null): string
    {
        /*
         * A template renders, so it is handed the pages and nothing else. The
         * arguments are the scope the template is given: $pages is what it
         * iterates, and being static this closure keeps $this away from it
         */
        $render = static function (string $view, array $pages): void {
            include $view;
        };

        ob_start();

        try {
            $render($view ?? $this->viewPath, $this->pages());
        } finally {
            /* A template that blew up leaves no buffer behind */
            $html = ob_get_clean();
        }

        return $html;
    }

    /**
     * Name the page parameter of the built urls
     *
     * @param string $name
     *
     * @return $this
     */
    public function setPageName(string $name): static
    {
        $this->pageName = $name;

        return $this;
    }

    /**
     * Point the links at a path
     *
     * @param string $path
     *
     * @return $this
     */
    public function withPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Carry query parameters along every link
     *
     * @param array $appends
     *
     * @return $this
     */
    public function appends(array $appends): static
    {
        $this->appends = $appends;

        return $this;
    }

    /**
     * Keep the page within the range the paginator knows of
     *
     * @param int $page
     *
     * @return int
     */
    protected function clamp(int $page): int
    {
        return $page;
    }

    /**
     * Build a link to the given page
     *
     * @param int             $page
     * @param int|string|null $name what the view prints, the number by default
     *
     * @return Page
     */
    protected function link(int $page, int|string|null $name = null): Page
    {
        return Page::link($page, $this->buildUrl($page), $name ?? $page);
    }

    /**
     * Build url
     *
     * The first page is the path itself: "?page=1" points at what the path
     * already points at, and two urls for one page is one too many
     *
     * @param int $page
     *
     * @return string
     */
    protected function buildUrl(int $page): string
    {
        $query = http_build_query(
            $page > 1 ? [$this->pageName => $page] + $this->appends : $this->appends
        );

        if ($query === '') {
            /* An empty url would mean the current one, page parameter and all */
            return $this->path ?: '?';
        }

        return $this->path . '?' . $query;
    }
}
