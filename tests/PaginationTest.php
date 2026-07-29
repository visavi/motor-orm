<?php

namespace MotorORM\Tests;

use InvalidArgumentException;
use MotorORM\Page;
use MotorORM\Pagination;
use MotorORM\Tests\Models\Article;
use RuntimeException;

final class PaginationTest extends TestCase
{
    /**
     * What one test taught the paginator about the request, the next must not inherit
     */
    protected function tearDown(): void
    {
        Pagination::resolvePageUsing(null);
        Pagination::setPageName('page');

        unset($_GET['page'], $_GET['custom']);
    }

    /**
     * A paginator is usable the moment it is built
     */
    public function testFirstPage(): void
    {
        $paginator = new Pagination([], 100, 10);

        $this->assertEquals(1, $paginator->page);
        $this->assertEquals(0, $paginator->offset);
        $this->assertEquals(100, $paginator->total);
        $this->assertEquals(10, $paginator->perPage());
        $this->assertEquals(10, $paginator->lastPage());
        $this->assertTrue($paginator->hasPages());
    }

    /**
     * A paginator built by hand is told its page, it does not go looking
     */
    public function testRequestIsIgnored(): void
    {
        $_GET['page'] = '3';

        $paginator = new Pagination([], 100, 10);

        $this->assertEquals(1, $paginator->page);
        $this->assertEquals(0, $paginator->offset);
    }

    /**
     * Offset of the requested page
     */
    public function testRequestedPage(): void
    {
        $paginator = new Pagination([], 100, 10, 3);

        $this->assertEquals(3, $paginator->page);
        $this->assertEquals(20, $paginator->offset);
    }

    /**
     * A page beyond the last one falls back to the last page
     */
    public function testPageOverflow(): void
    {
        $paginator = new Pagination([], 25, 10, 99);

        $this->assertEquals(3, $paginator->page);
        $this->assertEquals(20, $paginator->offset);
    }

    /**
     * A page below the first one falls back to the first page
     */
    public function testPageUnderflow(): void
    {
        $paginator = new Pagination([], 100, 10, -5);

        $this->assertEquals(1, $paginator->page);
        $this->assertEquals(0, $paginator->offset);
    }

    /**
     * The page cannot be moved out of range from the outside
     */
    public function testPageIsReadOnly(): void
    {
        $paginator = new Pagination([], 100, 10);

        $this->expectException(\Error::class);

        $paginator->page = 999;
    }

    /**
     * A page has to hold something
     */
    public function testEmptyLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Pagination([], 100, 0);
    }

    /**
     * A query asked for a page of nothing says so, whichever paginator
     */
    public function testPageOfNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one row');

        Article::query()->paginate(0);
    }

    /**
     * An empty result set stays on the first page
     */
    public function testEmptyTotal(): void
    {
        $paginator = new Pagination([], 0, 10, 5);

        $this->assertEquals(1, $paginator->page);
        $this->assertEquals(0, $paginator->offset);
        $this->assertEquals(1, $paginator->lastPage());
        $this->assertFalse($paginator->hasPages());
        $this->assertSame([], $paginator->pages());
    }

    /**
     * A single page needs no navigation
     */
    public function testSinglePage(): void
    {
        $paginator = new Pagination([], 5, 10);

        $this->assertFalse($paginator->hasPages());
        $this->assertEquals(1, $paginator->lastPage());
    }

    /**
     * Total page count
     */
    public function testLastPage(): void
    {
        $this->assertEquals(10, new Pagination([], 100, 10)->lastPage());
        $this->assertEquals(3, new Pagination([], 25, 10)->lastPage());
        $this->assertEquals(1, new Pagination([], 0, 10)->lastPage());
    }

    /**
     * Numbers of the first and the last row of the page
     */
    public function testItemRange(): void
    {
        $paginator = new Pagination([], 45, 10, 2);

        $this->assertEquals(11, $paginator->firstItem());
        $this->assertEquals(20, $paginator->lastItem());
    }

    /**
     * The last page is short of a full one
     */
    public function testItemRangeOnTheLastPage(): void
    {
        $paginator = new Pagination([], 45, 10, 5);

        $this->assertEquals(41, $paginator->firstItem());
        $this->assertEquals(45, $paginator->lastItem());
    }

    /**
     * An empty result set has no rows to number
     */
    public function testItemRangeOfEmptyTotal(): void
    {
        $paginator = new Pagination([], 0, 10);

        $this->assertNull($paginator->firstItem());
        $this->assertNull($paginator->lastItem());
    }

    /**
     * Where the paginator stands
     */
    public function testEdges(): void
    {
        $first = new Pagination([], 45, 10, 1);
        $this->assertTrue($first->onFirstPage());
        $this->assertFalse($first->onLastPage());

        $middle = new Pagination([], 45, 10, 3);
        $this->assertFalse($middle->onFirstPage());
        $this->assertFalse($middle->onLastPage());

        $last = new Pagination([], 45, 10, 5);
        $this->assertFalse($last->onFirstPage());
        $this->assertTrue($last->onLastPage());
    }

    /**
     * A single page is both the first and the last one
     */
    public function testEdgesOfSinglePage(): void
    {
        $paginator = new Pagination([], 5, 10);

        $this->assertTrue($paginator->onFirstPage());
        $this->assertTrue($paginator->onLastPage());
    }

    /**
     * Url of any page, not only of the ones on show
     */
    public function testUrl(): void
    {
        $paginator = new Pagination([], 100, 10)
            ->withPath('/stories')
            ->appends(['sort' => 'title']);

        $this->assertEquals('/stories?page=7&sort=title', $paginator->url(7));
    }

    /**
     * A url is asked for a page that exists
     */
    public function testUrlOutOfRange(): void
    {
        $paginator = new Pagination([], 100, 10)->withPath('/stories');

        $this->assertEquals('/stories?page=10', $paginator->url(999));
        $this->assertEquals('/stories', $paginator->url(-5));
    }

    /**
     * The first page is the path itself, the parameter would say nothing
     */
    public function testUrlOfTheFirstPage(): void
    {
        $paginator = new Pagination([], 100, 10)->withPath('/stories');

        $this->assertEquals('/stories', $paginator->url(1));
    }

    /**
     * The appended parameters stay on the first page
     */
    public function testUrlOfTheFirstPageKeepsAppends(): void
    {
        $paginator = new Pagination([], 100, 10)
            ->withPath('/stories')
            ->appends(['sort' => 'title']);

        $this->assertEquals('/stories?sort=title', $paginator->url(1));
    }

    /**
     * Without a path the first page still has to drop the query
     *
     * An empty url would mean the current one, page parameter and all
     */
    public function testUrlOfTheFirstPageWithoutPath(): void
    {
        $paginator = new Pagination([], 100, 10);

        $this->assertEquals('?', $paginator->url(1));
    }

    /**
     * The rendered links point at the path itself on the first page
     */
    public function testLinksOfTheFirstPage(): void
    {
        $links = new Pagination([], 100, 10, 5)->withPath('/stories')->links();

        $this->assertStringContainsString('href="/stories"', $links);
        $this->assertStringNotContainsString('href="/stories?page=1"', $links);
    }

    /**
     * Page links
     */
    public function testItems(): void
    {
        $paginator = new Pagination([], 100, 10, 5);

        $items = $paginator->pages();
        $last  = $items[count($items) - 1];

        $this->assertContainsOnlyInstancesOf(Page::class, $items);

        $this->assertEquals('«', $items[0]->name);
        $this->assertEquals(4, $items[0]->number);
        $this->assertEquals('»', $last->name);
        $this->assertEquals(6, $last->number);

        $current = array_values(array_filter($items, static fn (Page $page) => $page->current));
        $this->assertCount(1, $current);
        $this->assertEquals(5, $current[0]->name);
        $this->assertNull($current[0]->url);

        $separators = array_filter($items, static fn (Page $page) => $page->separator);
        $this->assertCount(2, $separators);
    }

    /**
     * A wider window shows more pages around the current one
     */
    public function testCrumbs(): void
    {
        $paginator = new Pagination([], 100, 10, 5)->onEachSide(3);

        $numbers = array_map(
            static fn (Page $page) => $page->name,
            array_filter($paginator->pages(), static fn (Page $page) => ! $page->separator),
        );

        $this->assertEquals(['«', 1, 2, 3, 4, 5, 6, 7, 8, 10, '»'], array_values($numbers));
    }

    /**
     * Url building with path and appends
     */
    public function testBuildUrl(): void
    {
        $paginator = new Pagination([], 100, 10)
            ->withPath('/stories')
            ->appends(['sort' => 'title']);

        $items = $paginator->pages();

        $this->assertEquals('/stories?page=2&sort=title', $items[count($items) - 1]->url);
    }

    /**
     * Custom page name
     */
    public function testCustomPageName(): void
    {
        Pagination::setPageName('custom');

        $paginator = new Pagination([], 100, 10)->withPath('/stories');

        $items = $paginator->pages();

        $this->assertEquals('/stories?custom=2', $items[count($items) - 1]->url);
    }

    /**
     * Rendered links
     */
    public function testLinks(): void
    {
        $links = new Pagination([], 30, 10)->links();

        $this->assertStringContainsString('pagination', $links);
        $this->assertStringContainsString('page=2', $links);
    }

    /**
     * Rendered links of an empty result set contain no pages
     */
    public function testEmptyLinks(): void
    {
        $this->assertStringNotContainsString('page-item', new Pagination([], 0, 10)->links());
    }

    /**
     * A path breaking out of the attribute is escaped
     */
    public function testLinksEscapeThePath(): void
    {
        $links = new Pagination([], 30, 10)
            ->withPath('/list"><script>alert(1)</script>')
            ->links();

        $this->assertStringNotContainsString('<script>', $links);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $links);
    }

    /**
     * Custom view
     */
    public function testCustomView(): void
    {
        $view = $this->view('<?php foreach ($pages as $page): ?>[<?= $page->separator ? "..." : $page->name ?>]<?php endforeach; ?>');

        $paginator = new Pagination([], 30, 10);

        $this->assertEquals('[1][2][3][»]', $paginator->links($view));

        unlink($view);
    }

    /**
     * A view given to links() renders that once and nothing after
     */
    public function testCustomViewIsNotRemembered(): void
    {
        $view = $this->view('<?php echo "мой";');

        $paginator = new Pagination([], 30, 10);

        $this->assertEquals('мой', $paginator->links($view));
        $this->assertStringContainsString('page-item', $paginator->links());

        unlink($view);
    }

    /**
     * A view that blows up leaves no buffer behind
     */
    public function testFailedViewLeavesNoBuffer(): void
    {
        $view = $this->view('<?php throw new \RuntimeException("boom");');

        $paginator = new Pagination([], 30, 10);
        $level     = ob_get_level();

        try {
            $paginator->links($view);
            $this->fail('the view was expected to throw');
        } catch (RuntimeException) {
            $this->assertEquals($level, ob_get_level());
        } finally {
            unlink($view);
        }
    }

    /**
     * A view renders, it does not reach into the paginator
     */
    public function testViewCannotReachThePaginator(): void
    {
        $view = $this->view('<?php echo isset($this) ? "yes" : "no";');

        $paginator = new Pagination([], 30, 10);

        $this->assertEquals('no', $paginator->links($view));

        unlink($view);
    }

    /**
     * Paginated collection
     */
    public function testCollectionPaginate(): void
    {
        $find = Article::query()->page(2)->paginate(5);

        $this->assertInstanceOf(Pagination::class, $find);
        $this->assertCount(5, $find);
        $this->assertEquals(2, $find->currentPage());
        $this->assertEquals(20, $find->total());
        $this->assertEquals(4, $find->lastPage());
        $this->assertEquals(5, $find->perPage());
        $this->assertTrue($find->hasPages());
        $this->assertEquals(6, $find->firstItem());
        $this->assertEquals(10, $find->lastItem());
        $this->assertFalse($find->onFirstPage());
        $this->assertFalse($find->onLastPage());
        $this->assertEquals(6, $find[0]->id);

        $find->withPath('/list')->appends(['q' => 'x']);
        $this->assertStringContainsString('/list?q=x', $find->links());
        $this->assertEquals('/list?page=4&q=x', $find->url(4));
    }

    /**
     * A query left to itself takes the page from the request
     */
    public function testPaginateResolvesPageFromRequest(): void
    {
        $_GET['page'] = '2';

        $find = Article::query()->paginate(5);

        $this->assertEquals(2, $find->currentPage());
        $this->assertEquals(6, $find[0]->id);
    }

    /**
     * A page spelled out beats whatever the request says
     */
    public function testPageBeatsTheRequest(): void
    {
        $_GET['page'] = '3';

        $find = Article::query()->page(1)->paginate(5);

        $this->assertEquals(1, $find->currentPage());
    }

    /**
     * The page comes from wherever it is told to come from
     */
    public function testResolvePageUsing(): void
    {
        Pagination::resolvePageUsing(static fn () => 3);

        $this->assertEquals(3, Article::query()->paginate(5)->currentPage());
    }

    /**
     * The resolver is told which parameter carries the page
     */
    public function testResolverIsGivenThePageName(): void
    {
        Pagination::setPageName('custom');
        Pagination::resolvePageUsing(static fn (string $name) => $name === 'custom' ? 2 : 1);

        $this->assertEquals(2, Article::query()->paginate(5)->currentPage());
    }

    /**
     * The parameter the page is read from is the one the links are built with
     */
    public function testCustomPageNameIsReadFromRequest(): void
    {
        Pagination::setPageName('custom');

        $_GET['custom'] = '2';
        $_GET['page']   = '4';

        $find = Article::query()->paginate(5);

        $this->assertEquals(2, $find->currentPage());
        $this->assertEquals('?custom=3', $find->url(3));
    }

    /**
     * A page that is no number is the first one
     */
    public function testUnreadablePageFallsBackToTheFirst(): void
    {
        $_GET['page'] = 'nonsense';

        $this->assertEquals(1, Article::query()->paginate(5)->currentPage());

        $_GET['page'] = ['4'];

        $this->assertEquals(1, Article::query()->paginate(5)->currentPage());
    }

    /**
     * There is no page before the first one
     */
    public function testPageBelowTheFirst(): void
    {
        $this->assertEquals(1, Article::query()->page(0)->paginate(5)->currentPage());
        $this->assertEquals(1, Article::query()->page(-5)->paginate(5)->currentPage());

        $_GET['page'] = '-2';

        $this->assertEquals(1, Article::query()->paginate(5)->currentPage());
    }

    /**
     * A view of one's own on the paginated collection
     */
    public function testCollectionPaginateView(): void
    {
        $view = $this->view('<?php foreach ($pages as $page): ?>[<?= $page->name ?>]<?php endforeach; ?>');

        $find = Article::query()->paginate(10);

        $this->assertEquals('[1][2][»]', $find->links($view));

        unlink($view);
    }

    /**
     * Write a template to a file of its own
     *
     * @param string $template
     *
     * @return string
     */
    private function view(string $template): string
    {
        $path = tempnam(sys_get_temp_dir(), 'motor-orm-view');
        file_put_contents($path, $template);

        return $path;
    }
}
