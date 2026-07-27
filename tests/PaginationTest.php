<?php

namespace MotorORM\Tests;

use MotorORM\CollectionPaginate;
use MotorORM\Pagination;
use MotorORM\Tests\Models\Article;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Pagination::class)]
#[CoversClass(CollectionPaginate::class)]
final class PaginationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['page']);
    }

    /**
     * Offset of the first page
     *
     */
    public function testFirstPage(): void
    {
        $paginator = (new Pagination())->create(100, 10);

        $this->assertEquals(1, $paginator->page);
        $this->assertEquals(0, $paginator->offset);
        $this->assertEquals(100, $paginator->total);
    }

    /**
     * Offset of the requested page
     *
     */
    public function testRequestedPage(): void
    {
        $_GET['page'] = '3';

        $paginator = (new Pagination())->create(100, 10);

        $this->assertEquals(3, $paginator->page);
        $this->assertEquals(20, $paginator->offset);
    }

    /**
     * A page beyond the last one falls back to the last page
     *
     */
    public function testPageOverflow(): void
    {
        $_GET['page'] = '99';

        $paginator = (new Pagination())->create(25, 10);

        $this->assertEquals(3, $paginator->page);
        $this->assertEquals(20, $paginator->offset);
    }

    /**
     * An empty result set stays on the first page
     *
     */
    public function testEmptyTotal(): void
    {
        $_GET['page'] = '5';

        $paginator = (new Pagination())->create(0, 10);

        $this->assertEquals(1, $paginator->page);
        $this->assertEquals(0, $paginator->offset);
        $this->assertSame([], $paginator->items());
    }

    /**
     * An explicitly set page wins over the request
     */
    public function testSetPage(): void
    {
        $_GET['page'] = '3';

        $paginator = new Pagination();
        $paginator->setPage(2);
        $paginator->create(100, 10);

        $this->assertEquals(2, $paginator->page);
        $this->assertEquals(10, $paginator->offset);
    }

    /**
     * An explicitly set page is clamped to the available range
     */
    public function testSetPageOverflow(): void
    {
        $paginator = new Pagination();
        $paginator->setPage(99);
        $paginator->create(25, 10);

        $this->assertEquals(3, $paginator->page);
        $this->assertEquals(20, $paginator->offset);
    }

    /**
     * Total page count
     */
    public function testPageCount(): void
    {
        $this->assertEquals(10, (new Pagination())->create(100, 10)->pageCount());
        $this->assertEquals(3, (new Pagination())->create(25, 10)->pageCount());
        $this->assertEquals(0, (new Pagination())->create(0, 10)->pageCount());
    }

    /**
     * Page links
     *
     */
    public function testItems(): void
    {
        $_GET['page'] = '5';

        $paginator = (new Pagination())->create(100, 10);
        $items = $paginator->items();

        $this->assertEquals('«', $items[0]['name']);
        $this->assertEquals(4, $items[0]['page']);
        $this->assertEquals('»', $items[count($items) - 1]['name']);
        $this->assertEquals(6, $items[count($items) - 1]['page']);

        $current = array_values(array_filter($items, static fn ($item) => isset($item['current'])));
        $this->assertCount(1, $current);
        $this->assertEquals(5, $current[0]['name']);

        $separators = array_filter($items, static fn ($item) => isset($item['separator']));
        $this->assertCount(2, $separators);
    }

    /**
     * Url building with path and appends
     *
     */
    public function testBuildUrl(): void
    {
        $paginator = (new Pagination())->create(100, 10);
        $paginator->withPath('/stories');
        $paginator->appends(['sort' => 'title']);

        $items = $paginator->items();

        $this->assertEquals('/stories?page=2&sort=title', $items[count($items) - 1]['link']);
    }

    /**
     * Custom page name
     *
     */
    public function testCustomPageName(): void
    {
        $_GET['custom'] = '2';

        $paginator = (new Pagination(null, 'custom'))->create(100, 10);

        $this->assertEquals(2, $paginator->page);

        unset($_GET['custom']);
    }

    /**
     * Rendered links
     *
     */
    public function testLinks(): void
    {
        $paginator = (new Pagination())->create(30, 10);

        $links = $paginator->links();

        $this->assertStringContainsString('pagination', $links);
        $this->assertStringContainsString('page=2', $links);
    }

    /**
     * Rendered links of an empty result set contain no pages
     *
     */
    public function testEmptyLinks(): void
    {
        $paginator = (new Pagination())->create(0, 10);

        $this->assertStringNotContainsString('page-item', $paginator->links());
    }

    /**
     * Custom view
     *
     */
    public function testCustomView(): void
    {
        $view = sys_get_temp_dir() . '/motor-orm-pagination-view.php';
        file_put_contents($view, '<?php foreach ($pages as $page): ?>[<?= $page["name"] ?? "..." ?>]<?php endforeach; ?>');

        $paginator = (new Pagination())->create(30, 10);
        $paginator->setView($view);

        $this->assertEquals('[1][2][3][»]', $paginator->links());

        unlink($view);
    }

    /**
     * Paginated collection
     *
     */
    public function testCollectionPaginate(): void
    {
        $_GET['page'] = '2';

        $find = Article::query()->paginate(5);

        $this->assertInstanceOf(CollectionPaginate::class, $find);
        $this->assertCount(5, $find);
        $this->assertEquals(2, $find->currentPage());
        $this->assertEquals(20, $find->total());
        $this->assertEquals(6, $find[0]->id);

        $find->withPath('/list')->appends(['q' => 'x']);
        $this->assertStringContainsString('/list?page=1&q=x', $find->links());
    }
}
