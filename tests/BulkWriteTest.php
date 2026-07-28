<?php

namespace MotorORM\Tests;

use MotorORM\Model;
use MotorORM\Tests\Models\Bulk;
use SplFileObject;

final class BulkWriteTest extends TestCase
{
    /** Rows the table holds before it is written to */
    private const int ROWS = 20000;

    /**
     * A table large enough for holding it to be noticeable, fresh for every
     * case because each of them changes it
     */
    protected function setUp(): void
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
     * The whole table is updated without holding the rows it touches
     *
     * A row is written as it is read, so what a write costs does not grow
     * with how much of the table it changes
     */
    public function testUpdateDoesNotHoldTheKeys(): void
    {
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $affected = Bulk::query()->update(['text' => 'Другой текст']);

        $this->assertSame(self::ROWS, $affected);
        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame('Другой текст', Bulk::query()->find(self::ROWS)->text);
    }

    /**
     * The same holds for a delete of the whole table
     */
    public function testDeleteDoesNotHoldTheKeys(): void
    {
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $affected = Bulk::query()->whereLike('title', 'Заголовок%')->delete();

        $this->assertSame(self::ROWS, $affected);
        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame(0, Bulk::query()->count());
    }

    /**
     * A condition still tells the rows apart
     */
    public function testUpdateTouchesOnlyTheRowsItMatches(): void
    {
        $affected = Bulk::query()->where('id', '<', 4)->update(['text' => 'Другой текст']);

        $this->assertSame(3, $affected);
        $this->assertSame(3, Bulk::query()->where('text', 'Другой текст')->count());
        $this->assertSame('Текст текст текст', Bulk::query()->find(4)->text);
    }
}
