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
 * @version 1.0
 */
class Collection implements Countable, IteratorAggregate, ArrayAccess
{
    /**
     * Initializes a new collection.
     */
    public function __construct(
        protected array $elements = [],
    ) {}

    /**
     * Creates a new instance from the specified elements.
     *
     * This method is provided for derived classes to specify how a new
     * instance should be created when constructor semantics have changed.
     *
     * @param array $elements Elements.
     *
     * @return static
     */
    protected function createFrom(array $elements): static
    {
        return new static($elements);
    }

    /**
     *  Gets a native PHP array representation of the collection.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->elements;
    }

    /**
     * Sets the internal iterator to the first element in the collection and returns this element.
     *
     * @return false|mixed
     */
    public function first(): mixed
    {
        return reset($this->elements);
    }

    /**
     * Sets the internal iterator to the last element in the collection and returns this element.
     *
     * @return false|mixed
     */
    public function last(): mixed
    {
        return end($this->elements);
    }

    /**
     * Gets the key/index of the element at the current iterator position.
     *
     * @return int|string|null
     */
    public function key(): int|string|null
    {
        return key($this->elements);
    }

    /**
     * Moves the internal iterator position to the next element and returns this element.
     *
     * @return false|mixed
     */
    public function next(): mixed
    {
        return next($this->elements);
    }

    /**
     * Gets the element of the collection at the current iterator position.
     *
     * @return false|mixed
     */
    public function current(): mixed
    {
        return current($this->elements);
    }

    /**
     * Removes the element at the specified index from the collection.
     *
     * @param string|int $key
     *
     * @return mixed|null
     */
    public function remove(string|int $key): mixed
    {
        if (! isset($this->elements[$key]) && ! array_key_exists($key, $this->elements)) {
            return null;
        }

        $removed = $this->elements[$key];
        unset($this->elements[$key]);

        return $removed;
    }

    /**
     * Removes the specified element from the collection, if it is found.
     *
     * @param mixed $element
     *
     * @return bool
     */
    public function removeElement(mixed $element): bool
    {
        $key = array_search($element, $this->elements, true);

        if ($key === false) {
            return false;
        }

        unset($this->elements[$key]);

        return true;
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
        return $this->containsKey($offset);
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
            $this->add($value);

            return;
        }

        $this->set($offset, $value);
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
        $this->remove($offset);
    }

    /**
     * Checks whether the collection contains an element with the specified key/index.
     *
     * @param string|int $key
     *
     * @return bool
     */
    public function containsKey(string|int $key): bool
    {
        return isset($this->elements[$key]) || array_key_exists($key, $this->elements);
    }

    /**
     * Checks whether an element is contained in the collection.
     * This is an O(n) operation, where n is the size of the collection.
     *
     * @param mixed $element
     *
     * @return bool
     */
    public function contains(mixed $element): bool
    {
        return in_array($element, $this->elements, true);
    }

    /**
     * @param $element
     *
     * @return false|int|string
     */
    public function indexOf($element): bool|int|string
    {
        return array_search($element, $this->elements, true);
    }

    /**
     * Gets the element at the specified key/index.
     *
     * @param string|int $key
     *
     * @return mixed|null
     */
    public function get(string|int $key): mixed
    {
        return $this->elements[$key] ?? null;
    }

    /**
     * Gets all keys/indices of the collection.
     *
     * @return array
     */
    public function getKeys(): array
    {
        return array_keys($this->elements);
    }

    /**
     * Gets all values of the collection.
     *
     * @return array
     */
    public function getValues(): array
    {
        return array_values($this->elements);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->elements);
    }

    /**
     * Sets an element in the collection at the specified key/index.
     *
     * @param string|int $key
     * @param mixed      $value
     *
     * @return void
     */
    public function set(string|int $key, mixed $value): void
    {
        $this->elements[$key] = $value;
    }

    /**
     * Adds an element at the end of the collection.
     *
     * @param mixed $element
     *
     * @return bool
     */
    public function add(mixed $element): bool
    {
        $this->elements[] = $element;

        return true;
    }

    /**
     * Checks whether the collection is empty (contains no elements).
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->elements);
    }

    /**
     * Checks whether the collection is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return ! empty($this->elements);
    }

    /**
     * @return Traversable<int|string, mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
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

    /**
     * Clears the collection, removing all elements.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->elements = [];
    }

    /**
     * Extracts a slice of $length elements starting at position $offset from the Collection.
     *
     * If $length is null it returns all elements from $offset to the end of the Collection.
     * Keys have to be preserved by this method. Calling this method will only return the
     * selected slice and NOT change the elements contained in the collection slice is called on.
     *
     * @param int      $offset
     * @param int|null $length
     *
     * @return array
     */
    public function slice($offset, $length = null)
    {
        return array_slice($this->elements, $offset, $length, true);
    }

    /**
     * Pluck
     *
     * @param string      $value
     * @param string|null $key
     *
     * @return array
     */
    public function pluck(string $value, ?string $key = null)
    {
        if ($key === null) {
            return array_column($this->elements, $value);
        }

        return array_column($this->elements, $value, $key);
    }
}
