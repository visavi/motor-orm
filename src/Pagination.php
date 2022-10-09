<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * Page navigation
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 * @version 3.0
 */
class Pagination
{
    public int $limit;
    public int $total;
    public int $crumbs;
    public int $offset;
    public int $page;
    public ?string $path = null;
    public array $appends;

    public function __construct(
        protected ?string $view = null,
        protected ?string $pageName = null,
    ) {
        $this->pageName = $pageName ?: 'page';
        $this->view = $view ?: __DIR__ . '/views/bootstrap5.php';
    }

    /**
     * Create
     *
     * @param int $total
     * @param int $limit
     * @param int $crumbs
     *
     * @return $this
     */
    public function create(int $total, int $limit = 10, int $crumbs = 1): self
    {
        $this->limit  = $limit;
        $this->total  = $total;
        $this->crumbs = $crumbs;
        $this->page   = $this->page();
        $this->offset = $this->offset();

        return $this;
    }

    /**
     * Get offset
     *
     * @return int
     */
    public function offset(): int
    {
        if ($this->total === 0) {
            $this->page = 1;
        } elseif ($this->total && $this->page * $this->limit >= $this->total) {
            $this->page = (int) ceil($this->total / $this->limit);
        }

        return $this->page * $this->limit - $this->limit;
    }

    /**
     * Get current page
     *
     * @return int
     */
    public function page(): int
    {
        return ! empty($_GET[$this->pageName]) ? abs((int) $_GET[$this->pageName]) : 1;
    }

    /**
     * Get items
     *
     * @return array
     */
    public function items(): array
    {
        if (! $this->total) {
            return [];
        }

        $pages      = [];
        $pageCount  = (int) ceil($this->total / $this->limit);
        $indexFirst = max($this->page - $this->crumbs, 1);
        $indexLast  = min($this->page + $this->crumbs, $pageCount);

        if ($this->page !== 1) {
            $pages[] = [
                'link' => $this->buildUrl($this->page - 1),
                'page' => $this->page - 1,
                'name' => '«',
            ];
        }

        if ($this->page > $this->crumbs + 1) {
            $pages[] = [
                'link' => $this->buildUrl(1),
                'page' => 1,
                'name' => 1,
            ];
            if ($this->page !== $this->crumbs + 2) {
                $pages[] = [
                    'separator' => true,
                ];
            }
        }

        for ($i = $indexFirst; $i <= $indexLast; $i++) {
            if ($i === $this->page) {
                $pages[] = [
                    'current' => true,
                    'name'    => $i,
                ];
            } else {
                $pages[] = [
                    'link' => $this->buildUrl($i),
                    'page' => $i,
                    'name' => $i,
                ];
            }
        }

        if ($this->page < $pageCount - $this->crumbs) {
            if ($this->page !== $pageCount - $this->crumbs - 1) {
                $pages[] = [
                    'separator' => true,
                ];
            }

            $pages[] = [
                'link' => $this->buildUrl($pageCount),
                'page' => $pageCount,
                'name' => $pageCount,
            ];
        }

        if ($this->page !== $pageCount) {
            $pages[] = [
                'link' => $this->buildUrl($this->page + 1),
                'page' => $this->page + 1,
                'name' => '»',
            ];
        }

        return $pages;
    }

    /**
     * Get rendered links
     *
     * @return string
     */
    public function links(): string
    {
        ob_start();
        $pages = $this->items();
        include($this->view);

        return ob_get_clean();
    }

    /**
     * Set view
     *
     * @param string $view
     *
     * @return void
     */
    public function setView(string $view): void
    {
        $this->view = $view;
    }

    /**
     * Set page name
     *
     * @param string $name
     *
     * @return void
     */
    public function setPageName(string $name): void
    {
        $this->pageName = $name;
    }

    /**
     * Add path
     *
     * @param string $path
     *
     * @return void
     */
    public function withPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * Append params url
     *
     * @param array $appends
     *
     * @return void
     */
    public function appends(array $appends): void
    {
        $this->appends = $appends;
    }

    /**
     * Build url
     *
     * @param int $page
     *
     * @return string
     */
    protected function buildUrl(int $page): string
    {
        return $this->path . '?' . http_build_query([$this->pageName => $page] + $this->appends);
    }
}
