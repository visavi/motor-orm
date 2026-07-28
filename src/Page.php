<?php

declare(strict_types=1);

namespace MotorORM;

/**
 * One entry of a page navigation
 *
 * A page is either a link to another page, the page being shown, or the gap
 * between two distant ranges. Which one it is a view can ask instead of
 * guessing from the keys that happen to be there
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 */
final readonly class Page
{
    /**
     * @param int|string  $name      what the view prints, a number or an arrow
     * @param string|null $url       null on the current page and on a gap
     * @param int|null    $number    the page it leads to, null on a gap
     * @param bool        $current   whether it is the page being shown
     * @param bool        $separator whether it stands for the skipped pages
     */
    private function __construct(
        public int|string $name,
        public ?string $url = null,
        public ?int $number = null,
        public bool $current = false,
        public bool $separator = false,
    ) {}

    /**
     * A page the reader can go to
     *
     * @param int        $number
     * @param string     $url
     * @param int|string $name
     *
     * @return self
     */
    public static function link(int $number, string $url, int|string $name): self
    {
        return new self($name, $url, $number);
    }

    /**
     * The page being shown
     *
     * @param int $number
     *
     * @return self
     */
    public static function current(int $number): self
    {
        return new self($number, null, $number, current: true);
    }

    /**
     * The gap standing for the pages left out
     *
     * @return self
     */
    public static function separator(): self
    {
        return new self('...', separator: true);
    }
}
