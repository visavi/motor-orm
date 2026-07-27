<?php

namespace MotorORM\Tests;

use MotorORM\Collection;
use MotorORM\Tests\Models\Article;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Collection::class)]
final class CollectionTest extends TestCase
{
    /**
     * Last with callback must not reorder the collection
     *
     */
    public function testLastWithCallbackKeepsOrder(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);

        $found = $collection->last(static fn ($value) => $value < 3);

        $this->assertEquals(2, $found);
        $this->assertSame([1, 2, 3, 4, 5], $collection->all());
    }

    /**
     * Contains must treat a string as a value, not as a callable
     *
     */
    public function testContainsStringMatchingFunctionName(): void
    {
        $collection = new Collection(['strlen', 'foo']);

        $this->assertTrue($collection->contains('strlen'));
        $this->assertFalse($collection->contains('trim'));
    }

    /**
     * Search must treat a string as a value, not as a callable
     *
     */
    public function testSearchStringMatchingFunctionName(): void
    {
        $collection = new Collection(['foo', 'strlen']);

        $this->assertEquals(1, $collection->search('strlen'));
    }

    /**
     * Contains with a closure
     *
     */
    public function testContainsClosure(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertTrue($collection->contains(static fn ($value) => $value === 2));
        $this->assertFalse($collection->contains(static fn ($value) => $value === 9));
    }

    /**
     * Search with a closure
     *
     */
    public function testSearchClosure(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertEquals(2, $collection->search(static fn ($value) => $value === 3));
        $this->assertFalse($collection->search(static fn ($value) => $value === 9));
    }

    /**
     * Contains must not match a value that is only loosely equal
     *
     */
    public function testContainsNullValue(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertFalse($collection->contains(null));
    }

    /**
     * First returns the first item
     *
     */
    public function testFirst(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertEquals(1, $collection->first());
        $this->assertEquals(2, $collection->first(static fn ($value) => $value > 1));
        $this->assertNull($collection->first(static fn ($value) => $value > 9));
        $this->assertNull((new Collection())->first());
    }

    /**
     * Last returns the last item
     *
     */
    public function testLast(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertEquals(3, $collection->last());
        $this->assertNull($collection->last(static fn ($value) => $value > 9));
        $this->assertNull((new Collection())->last());
    }

    /**
     * Filter returns a new collection and keeps the original intact
     *
     */
    public function testFilter(): void
    {
        $collection = new Collection([1, 0, 2, null, 3]);

        $this->assertSame([1, 2, 3], $collection->filter()->values());
        $this->assertSame([2, 3], $collection->filter(static fn ($value) => $value > 1)->values());
        $this->assertCount(5, $collection);
    }

    /**
     * Pluck extracts a column
     *
     */
    public function testPluck(): void
    {
        $collection = new Collection([
            ['id' => 1, 'name' => 'one'],
            ['id' => 2, 'name' => 'two'],
        ]);

        $this->assertInstanceOf(Collection::class, $collection->pluck('name'));
        $this->assertSame(['one', 'two'], $collection->pluck('name')->all());
        $this->assertSame([1 => 'one', 2 => 'two'], $collection->pluck('name', 'id')->all());
    }

    /**
     * Key by a field
     *
     */
    public function testKeyBy(): void
    {
        $collection = new Collection([
            ['id' => 5, 'name' => 'five'],
            ['id' => 7, 'name' => 'seven'],
        ]);

        $keyed = $collection->keyBy('id');

        $this->assertInstanceOf(Collection::class, $keyed);
        $this->assertSame([
            5 => ['id' => 5, 'name' => 'five'],
            7 => ['id' => 7, 'name' => 'seven'],
        ], $keyed->all());
        $this->assertSame([0, 1], $collection->keys());
    }

    /**
     * Key by a closure
     *
     */
    public function testKeyByClosure(): void
    {
        $collection = new Collection([
            ['id' => 5, 'name' => 'five'],
            ['id' => 7, 'name' => 'seven'],
        ]);

        $keyed = $collection->keyBy(static fn (array $item) => 'row' . $item['id']);

        $this->assertSame(['row5', 'row7'], $keyed->keys());
    }

    /**
     * The last item of a repeated key wins
     *
     */
    public function testKeyByDuplicateKeepsLast(): void
    {
        $collection = new Collection([
            ['id' => 1, 'name' => 'first'],
            ['id' => 1, 'name' => 'second'],
        ]);

        $keyed = $collection->keyBy('id');

        $this->assertCount(1, $keyed);
        $this->assertSame('second', $keyed[1]['name']);
    }

    /**
     * An item without the key is dropped
     *
     */
    public function testKeyByMissingField(): void
    {
        $collection = new Collection([
            ['id' => 1, 'name' => 'one'],
            ['name' => 'no key'],
        ]);

        $this->assertSame([1], $collection->keyBy('id')->keys());
    }

    /**
     * Key models by an attribute
     *
     */
    public function testKeyByModels(): void
    {
        $keyed = Article::query()->limit(3)->get()->keyBy('id');

        $this->assertSame([1, 2, 3], $keyed->keys());
        $this->assertEquals('Заголовок1', $keyed[1]->title);
    }

    /**
     * Slice returns a new collection preserving keys
     *
     */
    public function testSlice(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);

        $this->assertSame([1 => 2, 2 => 3], $collection->slice(1, 2)->all());
        $this->assertCount(5, $collection);
    }

    /**
     * Pull removes and returns an item
     *
     */
    public function testPullAndForget(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);

        $this->assertEquals(1, $collection->pull('a'));
        $this->assertNull($collection->pull('a'));
        $this->assertFalse($collection->has('a'));

        $collection->forget('b');
        $this->assertTrue($collection->isEmpty());
    }

    /**
     * Array access
     *
     */
    public function testArrayAccess(): void
    {
        $collection = new Collection();

        $collection[] = 'first';
        $collection['key'] = 'value';

        $this->assertEquals('first', $collection[0]);
        $this->assertEquals('value', $collection['key']);
        $this->assertTrue(isset($collection['key']));

        unset($collection['key']);
        $this->assertFalse(isset($collection['key']));
        $this->assertNull($collection['key']);
    }

    /**
     * Keys, values, get, put, push
     *
     */
    public function testAccessors(): void
    {
        $collection = new Collection(['a' => 1]);
        $collection->put('b', 2);
        $collection->push(3);

        $this->assertSame(['a', 'b', 0], $collection->keys());
        $this->assertSame([1, 2, 3], $collection->values());
        $this->assertEquals(2, $collection->get('b'));
        $this->assertEquals('default', $collection->get('missing', 'default'));
    }

    /**
     * Iteration and counting
     *
     */
    public function testIterationAndCount(): void
    {
        $collection = new Collection([1, 2, 3]);

        $this->assertSame([1, 2, 3], iterator_to_array($collection));
        $this->assertCount(3, $collection);
        $this->assertTrue($collection->isNotEmpty());

        $collection->clear();
        $this->assertTrue($collection->isEmpty());
    }
}
