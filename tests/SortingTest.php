<?php

namespace MotorORM\Tests;

use MotorORM\Model;
use MotorORM\Query;
use MotorORM\Tests\Models\Bulk;
use PHPUnit\Framework\Attributes\CoversClass;
use SplFileObject;

#[CoversClass(Query::class)]
final class SortingTest extends TestCase
{
    /** Rows of the table the memory cases are read from */
    private const int ROWS = 20000;

    /**
     * A table large enough for holding it to be noticeable
     */
    public static function setUpBeforeClass(): void
    {
        $file = new SplFileObject(new Bulk()->getPath(), 'wb');
        $file->setCsvControl(...Model::CSV_CONTROL);
        $file->fputcsv(['id', 'title', 'text']);

        for ($i = 1; $i <= self::ROWS; $i++) {
            $file->fputcsv([$i, 'Заголовок' . $i, 'Текст текст текст']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(new Bulk()->getPath());
    }

    /**
     * A few sorted rows are read without holding the table
     *
     * The rows still have to be looked at one by one, but carrying the few
     * that are asked for is enough, and that is what a page of a large table
     * comes down to
     */
    public function testSortedPageDoesNotHoldTheTable(): void
    {
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $rows = Bulk::query()->orderByDesc('id')->limit(10)->get();

        $this->assertCount(10, $rows);
        $this->assertEquals(self::ROWS, $rows[0]->id);
        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);
    }

    /**
     * Sorting by more than one column is the same either way
     */
    public function testSortedPageMatchesTheFullSort(): void
    {
        $page = Bulk::query()->orderBy('text')->orderByDesc('id')->limit(5)->get();
        $all  = Bulk::query()->orderBy('text')->orderByDesc('id')->get();

        $this->assertSame(
            $all->slice(0, 5)->pluck('id')->all(),
            $page->pluck('id')->all(),
        );
    }

    /**
     * Rows the sort cannot tell apart keep the order they lie in
     */
    public function testTiesKeepTheOrderOfTheFile(): void
    {
        $page = Bulk::query()->orderBy('text')->limit(4)->get();

        $this->assertSame([1, 2, 3, 4], $page->pluck('id')->all());
    }

    /**
     * A page past the first is sorted the same way
     */
    public function testSortedPageWithAnOffset(): void
    {
        $rows = Bulk::query()->orderByDesc('id')->offset(5)->limit(3)->get();

        $this->assertEquals([self::ROWS - 5, self::ROWS - 6, self::ROWS - 7], $rows->pluck('id')->all());
    }

    /**
     * Asking for more rows than the table holds is not an error
     */
    public function testLimitBeyondTheTable(): void
    {
        $rows = Bulk::query()->where('id', '<', 4)->orderByDesc('id')->limit(100)->get();

        $this->assertEquals([3, 2, 1], $rows->pluck('id')->all());
    }
}
