<?php

declare(strict_types=1);

namespace MotorORM;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Collection
 *
 * @license Code and contributions have MIT License
 * @link    https://visavi.net
 * @author  Alexander Grigorev <admin@visavi.net>
 * @version 2.0
 */
class Collection implements Countable, IteratorAggregate, ArrayAccess
{
    /**
     * Initializes a new collection.
     */
    public function __construct(
        protected array $items = [],
    ) {}

    /**
     *  Gets a native PHP array representation of the collection.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Gets the item at the specified key/index.
     *
     * @param string|int $key
     * @param mixed $default
     *
     * @return mixed|null
     */
    public function get(string|int $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * Sets the internal iterator to the first item in the collection and returns this item.
     *
     * @return false|mixed
     */
    public function first(): mixed
    {
        return reset($this->items);
    }

    /**
     * Sets the internal iterator to the last item in the collection and returns this item.
     *
     * @return false|mixed
     */
    public function last(): mixed
    {
        return end($this->items);
    }

    /**
     * Removes and returns the item at the specified index from the collection
     *
     * @param string|int $key
     *
     * @return mixed|null
     */
    public function pull(string|int $key): mixed
    {
        if (! array_key_exists($key, $this->items)) {
            return null;
        }

        $removed = $this->items[$key];
        unset($this->items[$key]);

        return $removed;
    }

    /**
     * Removes the item at the specified index from the collection
     *
     * @param string|int $key
     *
     * @return void
     */
    public function forget(string|int $key): void
    {
        $this->pull($key);
    }

    /**
     * Gets all keys/indices of the collection.
     *
     * @return array
     */
    public function keys(): array
    {
        return array_keys($this->items);
    }

    /**
     * Gets all values of the collection.
     *
     * @return array
     */
    public function values(): array
    {
        return array_values($this->items);
    }

    /**
     * Checks whether the collection contains an item with the specified key/index.
     *
     * @param string|int $key
     *
     * @return bool
     */
    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Checks whether an item is contained in the collection.
     * This is an O(n) operation, where n is the size of the collection.
     *
     * @param mixed $item
     *
     * @return bool
     */
    public function contains(mixed $item): bool
    {
        return in_array($item, $this->items, true);
    }

    /**
     * The search method searches the collection for the given value and returns its key if found.
     *
     * @param mixed $item
     * @param bool $strict
     *
     * @return bool|int|string
     */
    public function search(mixed $item, bool $strict = false): bool|int|string
    {
        return array_search($item, $this->items, $strict);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Sets an item in the collection at the specified key/index.
     *
     * @param string|int $key
     * @param mixed      $value
     *
     * @return void
     */
    public function put(string|int $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }

    /**
     * Adds an item at the end of the collection.
     *
     * @param mixed $item
     *
     * @return bool
     */
    public function push(mixed $item): bool
    {
        $this->items[] = $item;

        return true;
    }

    /**
     * Checks whether the collection is empty (contains no items).
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Checks whether the collection is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return ! empty($this->items);
    }

    /**
     * Clears the collection, removing all items.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * Extracts a slice of $length items starting at position $offset from the Collection.
     *
     * If $length is null it returns all items from $offset to the end of the Collection.
     * Keys have to be preserved by this method. Calling this method will only return the
     * selected slice and NOT change the items contained in the collection slice is called on.
     *
     * @param int      $offset
     * @param int|null $length
     *
     * @return self
     */
    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Pluck
     *
     * @param string      $value
     * @param string|null $key
     *
     * @return self
     */
    public function pluck(string $value, ?string $key = null): self
    {
        if ($key === null) {
            return new self(array_column($this->items, $value));
        }

        return new self(array_column($this->items, $value, $key));
    }

    /**
     * Filter
     *
     * @param callable|null $callback
     *
     * @return self
     */
    public function filter(callable $callback = null): self
    {
        if ($callback) {
            return new self(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }

        return new self(array_filter($this->items));
    }

    /**
     * Gets the key/index of the item at the current iterator position.
     *
     * @return int|string|null
     */
    public function key(): int|string|null
    {
        return key($this->items);
    }

    /**
     * Moves the internal iterator position to the next item and returns this item.
     *
     * @return false|mixed
     */
    public function next(): mixed
    {
        return next($this->items);
    }

    /**
     * Gets the item of the collection at the current iterator position.
     *
     * @return false|mixed
     */
    public function current(): mixed
    {
        return current($this->items);
    }

    /**
     * Required by interface ArrayAccess.
     *
     * @param $offset
     *
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Required by interface ArrayAccess.
     *
     * @param $offset
     * @param $value
     *
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        if (! isset($offset)) {
            $this->push($value);

            return;
        }

        $this->put($offset, $value);
    }

    /**
     * Required by interface ArrayAccess.
     *
     * @param $offset
     *
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Required by interface ArrayAccess.
     *
     * @param $offset
     *
     * @return void
     */
    public function offsetUnset($offset): void
    {
        $this->forget($offset);
    }

    /**
     * @return Traversable<int|string, mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Returns a string representation of this object.
     *
     * @return string
     */
    public function __toString()
    {
        return self::class . '@' . spl_object_hash($this);
    }
}
