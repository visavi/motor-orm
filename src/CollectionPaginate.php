<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * Collection pagination
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 * @version 1.0
 */
class CollectionPaginate extends Collection
{
    /**
     * Initializes a new collection
     */
    public function __construct(
        private array $elements,
        private Paginator $paginator,
    ) {
        parent::__construct($this->elements);
    }

    /**
     * Get current page
     *
     * @return int
     */
    public function currentPage(): int
    {
        return $this->paginator->page;
    }

    /**
     * Get total items
     *
     * @return int
     */
    public function totalItems(): int
    {
        return $this->paginator->total;
    }

    /**
     * Render links
     *
     * @return string
     */
    public function links()
    {
        return $this->paginator->links();
    }
}
