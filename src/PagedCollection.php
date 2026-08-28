<?php

declare(strict_types=1);

namespace MotorORM;

use Closure;
use InvalidArgumentException;

/**
 * A collection that knows it is one page of a longer read
 *
 * It holds the rows of that page and is walked over like any other collection,
 * and on top of that it knows where the page stands among the rest. Where the
 * page number came from is none of its business
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
abstract class PagedCollection extends Collection
{
    /** Rows on a page */
    protected(set) int $limit;

    /** The page being shown */
    protected(set) int $page;

    /** Rows to skip to reach the page */
    protected(set) int $offset;

    /**
     * Name of the page parameter, in the urls that are built and in the
     * request the current page is read from
     *
     * The page has to be known before a page of rows exists, so the name of
     * the parameter carrying it cannot belong to that page either
     */
    private static string $pageName = 'page';

    /**
     * Where the current page comes from when a query is not told it
     *
     * A library that reads csv knows nothing about requests, so nothing is
     * read from the environment: until an application says where the page
     * comes from, the first one is meant
     */
    private static ?Closure $pageResolver = null;

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
     * Name the page parameter
     *
     * @param string $name
     *
     * @return void
     */
    public static function setPageName(string $name): void
    {
        self::$pageName = $name;
    }

    /**
     * Name of the page parameter
     *
     * @return string
     */
    public static function pageName(): string
    {
        return self::$pageName;
    }

    /**
     * Say where the current page comes from
     *
     * The source is the application's to name — the query string of a request,
     * a PSR-7 request, a router, a console argument. Until it does, or a query
     * is told the page outright with page(), the first page is meant
     *
     * @param Closure|null $resolver called with the name of the page parameter
     *
     * @return void
     */
    public static function resolvePageUsing(?Closure $resolver): void
    {
        self::$pageResolver = $resolver;
    }

    /**
     * The page being asked for
     *
     * @return int never below the first page
     */
    public static function resolveCurrentPage(): int
    {
        if (! self::$pageResolver) {
            return 1;
        }

        $page = (self::$pageResolver)(self::$pageName);

        /* A page that is no number is no page, the first one is meant */
        return is_numeric($page) ? max(1, (int) $page) : 1;
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
     * Keep the page within the range the collection knows of
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
            $page > 1 ? [self::$pageName => $page] + $this->appends : $this->appends
        );

        if ($query === '') {
            /* An empty url would mean the current one, page parameter and all */
            return $this->path ?: '?';
        }

        return $this->path . '?' . $query;
    }
}
