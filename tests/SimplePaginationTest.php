<?php

namespace MotorORM\Tests;

use MotorORM\Page;
use MotorORM\SimplePagination;
use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\Story;
use MotorORM\Tests\Models\User;

final class SimplePaginationTest extends TestCase
{
    /**
     * What one test taught the paginator about the request, the next must not inherit
     */
    protected function tearDown(): void
    {
        SimplePagination::resolvePageUsing(null);
        SimplePagination::setPageName('page');

    }

    /**
     * A query left to itself takes the page the resolver names
     */
    public function testResolvesPage(): void
    {
        SimplePagination::resolvePageUsing(static fn () => 2);

        $find = Article::query()->simplePaginate(5);

        $this->assertEquals(2, $find->currentPage());
        $this->assertEquals(6, $find[0]->id);
    }

    /**
     * A simple paginator knows the page it is on and nothing about the rest
     */
    public function testFirstPage(): void
    {
        $paginator = new SimplePagination(self::rows(10), 10, 1, true);

        $this->assertEquals(1, $paginator->page);
        $this->assertEquals(0, $paginator->offset);
        $this->assertEquals(10, $paginator->perPage());
        $this->assertEquals(1, $paginator->firstItem());
        $this->assertEquals(10, $paginator->lastItem());
        $this->assertTrue($paginator->onFirstPage());
        $this->assertFalse($paginator->onLastPage());
        $this->assertTrue($paginator->hasMorePages());
        $this->assertTrue($paginator->hasPages());
    }

    /**
     * The row numbers follow the page
     */
    public function testMiddlePage(): void
    {
        $paginator = new SimplePagination(self::rows(10), 10, 3, true);

        $this->assertEquals(20, $paginator->offset);
        $this->assertEquals(21, $paginator->firstItem());
        $this->assertEquals(30, $paginator->lastItem());
        $this->assertFalse($paginator->onFirstPage());
    }

    /**
     * The last page is short of a full one and has nothing after it
     */
    public function testLastPage(): void
    {
        $paginator = new SimplePagination(self::rows(5), 10, 5, false);

        $this->assertEquals(41, $paginator->firstItem());
        $this->assertEquals(45, $paginator->lastItem());
        $this->assertFalse($paginator->hasMorePages());
        $this->assertTrue($paginator->onLastPage());
        $this->assertTrue($paginator->hasPages());
    }

    /**
     * A single page needs no navigation
     */
    public function testSinglePage(): void
    {
        $paginator = new SimplePagination(self::rows(5), 10, 1, false);

        $this->assertTrue($paginator->onFirstPage());
        $this->assertTrue($paginator->onLastPage());
        $this->assertFalse($paginator->hasPages());
        $this->assertSame([], $paginator->pages());
    }

    /**
     * An empty page has no rows to number
     */
    public function testEmptyPage(): void
    {
        $paginator = new SimplePagination([], 10, 1, false);

        $this->assertNull($paginator->firstItem());
        $this->assertNull($paginator->lastItem());
        $this->assertSame([], $paginator->pages());
    }

    /**
     * Only the arrows, there is no way to know what the numbers would be
     */
    public function testItems(): void
    {
        $items = new SimplePagination(self::rows(10), 10, 3, true)->withPath('/stories')->pages();

        $this->assertContainsOnlyInstancesOf(Page::class, $items);
        $this->assertCount(2, $items);

        $this->assertEquals('«', $items[0]->name);
        $this->assertEquals(2, $items[0]->number);
        $this->assertEquals('/stories?page=2', $items[0]->url);

        $this->assertEquals('»', $items[1]->name);
        $this->assertEquals(4, $items[1]->number);
        $this->assertEquals('/stories?page=4', $items[1]->url);
    }

    /**
     * The first page has nothing to go back to
     */
    public function testItemsOnTheFirstPage(): void
    {
        $items = new SimplePagination(self::rows(10), 10, 1, true)->withPath('/stories')->pages();

        $this->assertCount(1, $items);
        $this->assertEquals('»', $items[0]->name);
    }

    /**
     * The last page has nothing to go forward to
     */
    public function testItemsOnTheLastPage(): void
    {
        $items = new SimplePagination(self::rows(3), 10, 4, false)->withPath('/stories')->pages();

        $this->assertCount(1, $items);
        $this->assertEquals('«', $items[0]->name);
        $this->assertEquals('/stories?page=3', $items[0]->url);
    }

    /**
     * The url of the page before this one, dropping the parameter on the first
     */
    public function testUrl(): void
    {
        $paginator = new SimplePagination(self::rows(10), 10, 3, true)
            ->withPath('/stories')
            ->appends(['sort' => 'title']);

        $this->assertEquals('/stories?sort=title', $paginator->url(1));
        $this->assertEquals('/stories?page=7&sort=title', $paginator->url(7));
    }

    /**
     * A simple paginator counts nothing, so it cannot be asked for a total
     */
    public function testTotalIsNotOffered(): void
    {
        $paginator = new SimplePagination(self::rows(10), 10, 1, true);

        $this->assertFalse(method_exists($paginator, 'total'));
        $this->assertFalse(method_exists($paginator, 'lastPage'));
    }

    /**
     * A page of a table, read without counting the rows
     */
    public function testQuery(): void
    {
        $find = Article::query()->page(2)->simplePaginate(5);

        $this->assertInstanceOf(SimplePagination::class, $find);
        $this->assertCount(5, $find);
        $this->assertEquals(6, $find[0]->id);
        $this->assertEquals(10, $find[4]->id);
        $this->assertEquals(2, $find->currentPage());
        $this->assertEquals(5, $find->perPage());
        $this->assertEquals(6, $find->firstItem());
        $this->assertEquals(10, $find->lastItem());
        $this->assertTrue($find->hasMorePages());

        $find->withPath('/list')->appends(['q' => 'x']);
        $this->assertSame('/list?q=x', $find->pages()[0]->url);
    }

    /**
     * The row that says there is more does not end up on the page
     */
    public function testLastPageOfTheTable(): void
    {
        $find = Article::query()->page(2)->simplePaginate(15);

        $this->assertCount(5, $find);
        $this->assertFalse($find->hasMorePages());
        $this->assertTrue($find->onLastPage());
        $this->assertEquals(16, $find->firstItem());
        $this->assertEquals(20, $find->lastItem());
    }

    /**
     * A page past the end of the table holds nothing
     */
    public function testPageBeyondTheTable(): void
    {
        $find = Article::query()->page(99)->simplePaginate(5);

        $this->assertCount(0, $find);
        $this->assertFalse($find->hasMorePages());
        $this->assertNull($find->firstItem());
    }

    /**
     * Conditions apply as they do to any other query
     */
    public function testQueryWithCondition(): void
    {
        $find = Article::query()->where('name', 'Миша')->simplePaginate(2);

        $this->assertCount(2, $find);
        $this->assertTrue($find->hasMorePages());
    }

    /**
     * Relations load for the page, and the extra row is none of their business
     */
    public function testRelationsOnAPage(): void
    {
        $find = Story::query()->simplePaginate(2);

        $this->assertCount(2, $find);
        $this->assertTrue($find->hasMorePages());

        foreach ($find as $story) {
            $this->assertInstanceOf(User::class, $story->user);
            $this->assertEquals($story->user_id, $story->user->id);
        }
    }

    /**
     * The collection of a simple pagination offers no total either
     */
    public function testCollectionOffersNoTotal(): void
    {
        $find = Article::query()->simplePaginate(5);

        $this->assertFalse(method_exists($find, 'total'));
        $this->assertFalse(method_exists($find, 'lastPage'));
    }

    /**
     * A page holding the given number of rows
     *
     * @param int $count
     *
     * @return array
     */
    private static function rows(int $count): array
    {
        return array_fill(0, $count, 'строка');
    }
}
