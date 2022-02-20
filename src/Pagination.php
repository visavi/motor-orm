<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * Page navigation
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 * @version 2.0
 */
class Pagination
{
    public int $limit;
    public int $total;
    public int $crumbs;
    public int $offset;
    public int $page;

    public function __construct(
        protected ?string $view = null,
    ) {
        if ($view === null) {
            $this->view = __DIR__ . '/views/bootstrap5.php';
        }
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
        if ($this->total && $this->page * $this->limit >= $this->total) {
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
        return ! empty($_GET['page']) ? abs((int) $_GET['page']) : 1;
    }

    /**
     * Get items
     *
     * @return array Сформированный блок с кнопками страниц
     */
    public function items(): array
    {
        if (! $this->total) {
            return [];
        }

        $pages = [];
        $pageCount = (int) ceil($this->total / $this->limit);
        $indexFirst = max($this->page - $this->crumbs, 1);
        $indexLast = min($this->page + $this->crumbs, $pageCount);

        if ($this->page !== 1) {
            $pages[] = [
                'page' => $this->page - 1,
                'name' => '«',
            ];
        }

        if ($this->page > $this->crumbs + 1) {
            $pages[] = [
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
                'page' => $pageCount,
                'name' => $pageCount,
            ];
        }

        if ($this->page !== $pageCount) {
            $pages[] = [
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
}
